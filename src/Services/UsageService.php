<?php

namespace Foysal50x\Tashil\Services;

use Foysal50x\Tashil\Contracts\FeatureUsageRepositoryInterface;
use Foysal50x\Tashil\Contracts\MeteredBilling;
use Foysal50x\Tashil\Contracts\Subscribable;
use Foysal50x\Tashil\Contracts\SubscriptionFeatureRepositoryInterface;
use Foysal50x\Tashil\Contracts\UsageLogRepositoryInterface;
use Foysal50x\Tashil\Enums\FeatureType;
use Foysal50x\Tashil\Enums\UsageOperation;
use Foysal50x\Tashil\Events\MeteredCharged;
use Foysal50x\Tashil\Events\MeteredChargeRejected;
use Foysal50x\Tashil\Events\UsageLimitWarning;
use Foysal50x\Tashil\Events\UsageReset;
use Foysal50x\Tashil\Managers\DatabaseManager;
use Foysal50x\Tashil\Models\Feature;
use Foysal50x\Tashil\Models\FeatureUsage;
use Foysal50x\Tashil\Models\Subscription;
use Foysal50x\Tashil\Traits\DispatchesEventsAfterCommit;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class UsageService
{
    use DispatchesEventsAfterCommit;

    public function __construct(
        protected DatabaseManager $db,
        protected FeatureUsageRepositoryInterface $usageRepo,
        protected SubscriptionFeatureRepositoryInterface $snapshotRepo,
        protected UsageLogRepositoryInterface $logRepo,
        protected EventStore $eventStore,
    ) {}

    /**
     * Atomically consume the feature.
     *
     * Limit features:    conditional UPDATE; returns false if the increment
     *                    would exceed limit_value.
     * Metered features:  asks the MeteredBilling to charge
     *                    `amount × unit_price` against the subscriber's
     *                    balance first; only on success does the counter
     *                    advance and a usage_log row get written.
     * Boolean / enum:    no counter semantics — return false because the
     *                    consume verb doesn't fit; use hasFeature() to gate.
     *
     * $idempotencyKey lets the caller dedupe app-level retries (e.g. a
     * client clicking "submit" twice). When null, a fresh UUID is
     * generated per call — useful only for provider-internal retries
     * within a single attempt, not for app-level retry safety.
     */
    public function increment(
        Subscription $subscription,
        string $featureSlug,
        float $amount = 1,
        ?string $idempotencyKey = null,
    ): bool {
        if ($amount <= 0) {
            return false;
        }

        if (! $subscription->isValid()) {
            return false;
        }

        $usage = $this->usageRepo->findBySlug($subscription, $featureSlug, withFeature: true);
        if (! $usage) {
            return false;
        }

        $feature = $usage->feature;
        if (! $feature || ! $feature->is_active) {
            return false;
        }

        // Boolean/Enum features have no counter semantics — consume is a
        // category error. Without this guard, atomicIncrement would happily
        // increment the row (limit_value is NULL for these types) and
        // silently bypass the access model defined by hasFeature().
        if ($feature->type === FeatureType::Boolean || $feature->type === FeatureType::Enum) {
            return false;
        }

        if ($feature->type === FeatureType::Metered) {
            return $this->consumeMetered($subscription, $usage, $feature, $amount, $idempotencyKey);
        }

        return $this->consumeCounter($subscription, $usage, $feature, $amount);
    }

    /**
     * Check whether a feature can be used right now.
     *
     * For Metered, this delegates to the provider's pre-flight
     * sufficiency check. Note this is advisory only — actual charging
     * happens in increment() and is the authoritative gate.
     *
     * Read-only by contract: never throws. When the metered provider
     * isn't configured (NullMeteredBilling), hasSufficientBalance
     * returns false and check() returns false — middleware and Blade
     * directives that call check() degrade to "deny" instead of 500.
     */
    public function check(Subscription $subscription, string $featureSlug, float $amount = 1): bool
    {
        if ($amount <= 0) {
            return false;
        }

        if (! $subscription->isValid()) {
            return false;
        }

        $snapshot = $this->snapshotRepo->findCurrentBySlug($subscription, $featureSlug);
        if (! $snapshot) {
            return false;
        }

        $feature = $snapshot->feature()->first();
        if (! $feature || ! $feature->is_active) {
            return false;
        }

        if ($snapshot->feature_type === FeatureType::Boolean) {
            return filter_var($snapshot->value, FILTER_VALIDATE_BOOLEAN);
        }

        if ($snapshot->feature_type === FeatureType::Limit) {
            $usage = $this->usageRepo->findBySlug($subscription, $featureSlug);
            if (! $usage) {
                return false;
            }

            if ($usage->isUnlimited()) {
                return true;
            }

            // If the reset window has elapsed but the cron hasn't promoted
            // the row yet, treat the counter as zero — otherwise we'd deny
            // access against a stale "full" counter. consumeCounter performs
            // the inline reset before actually mutating state.
            $effectiveUsage = $this->hasExpiredPeriod($usage)
                ? 0.0
                : (float) $usage->usage;

            return ($effectiveUsage + $amount) <= (float) $usage->limit_value;
        }

        if ($snapshot->feature_type === FeatureType::Metered) {
            $subscriber = $this->resolveSubscriber($subscription);
            if (! $subscriber) {
                return false;
            }

            $unitPrice = $this->parseUnitPrice($snapshot->value);
            if ($unitPrice === null) {
                return false;
            }

            return $this->resolveMeteredBilling($subscriber)->hasSufficientBalance(
                $subscriber,
                $this->subscriptionCurrency($subscription),
                $amount * $unitPrice,
            );
        }

        // Consumable and Enum: access is granted as long as a snapshot exists.
        return true;
    }

    /**
     * Absolute usage report — used for storage-style features where the
     * caller knows the current total (e.g. bytes used). Rejected for
     * Metered features: charges are per-delta, not per-snapshot.
     *
     * Zero is a valid report (means "no storage used"); negatives are
     * rejected because absolute usage can't be below zero.
     */
    public function reportStorage(Subscription $subscription, string $featureSlug, float $amount): bool
    {
        if ($amount < 0) {
            return false;
        }

        $usage = $this->usageRepo->findBySlug($subscription, $featureSlug, withFeature: true);
        if (! $usage) {
            return false;
        }

        $feature = $usage->feature;
        if ($feature && in_array($feature->type, [FeatureType::Metered, FeatureType::Boolean, FeatureType::Enum], true)) {
            return false;
        }

        // Wrap the counter write + log in one transaction so they can't
        // desync (every mutating service method is transactional).
        return (bool) $this->db->connection()->transaction(function () use ($subscription, $usage, $feature, $amount) {
            $previousUsage = (float) $usage->usage;
            $newUsage = $this->usageRepo->reportAbsolute($usage, $amount);

            $this->logRepo->create([
                'subscription_id' => $subscription->id,
                'feature_id'      => $usage->feature_id,
                'operation'       => UsageOperation::Report->value,
                'amount'          => $amount,
                'previous_usage'  => $previousUsage,
                'new_usage'       => $newUsage,
                'description'     => 'Storage report',
            ]);

            if ($feature) {
                $this->maybeFireLimitWarning($subscription, $usage, $feature, $previousUsage, $newUsage);
            }

            return true;
        });
    }

    public function resetUsage(Subscription $subscription, string $featureSlug): bool
    {
        $usage = $this->usageRepo->findBySlug($subscription, $featureSlug, withFeature: true);
        if (! $usage) {
            return false;
        }

        $this->db->connection()->transaction(function () use ($subscription, $usage) {
            $this->performReset($subscription, $usage);
        });

        return true;
    }

    /**
     * Zero a counter and advance its window, writing the reset log row and
     * usage.reset event. Shared by the manual reset, the scheduled reset, and
     * the inline reset in consumeCounter — so every reset (including the lazy
     * one) is captured for replay. Caller wraps this in a transaction.
     */
    protected function performReset(Subscription $subscription, FeatureUsage $usage): void
    {
        $previousUsage = (float) $usage->usage;
        $this->usageRepo->resetUsage($usage);

        $this->logRepo->create([
            'subscription_id' => $subscription->id,
            'feature_id'      => $usage->feature_id,
            'operation'       => UsageOperation::Reset->value,
            'amount'          => 0,
            'previous_usage'  => $previousUsage,
            'new_usage'       => 0,
            'description'     => 'Usage reset',
        ]);

        $this->eventStore->append($subscription, 'usage.reset', [
            'feature_id'     => $usage->feature_id,
            'previous_usage' => $previousUsage,
        ]);

        if ($usage->feature) {
            $this->dispatchAfterCommit(fn () => UsageReset::dispatch($subscription, $usage->feature, $previousUsage));
        }
    }

    public function resetAllUsage(Subscription $subscription): void
    {
        $this->usageRepo->resetAllUsage($subscription);
    }

    protected function consumeCounter(
        Subscription $subscription,
        FeatureUsage $usage,
        Feature $feature,
        float $amount,
    ): bool {
        // Reset + increment + log run in one transaction so a row can't end
        // up half-reset and so the consume log is atomic with the counter.
        return (bool) $this->db->connection()->transaction(function () use ($subscription, $usage, $feature, $amount) {
            // Inline reset for an expired quota so increment() doesn't fail
            // against a stale counter when the cron is late. Goes through
            // performReset so it writes a reset log + usage.reset event —
            // without this, a lazily-reset counter would be invisible to
            // log-replay (the new_usage deltas would not reconcile).
            if ($this->hasExpiredPeriod($usage)) {
                $this->performReset($subscription, $usage);
                $usage->refresh();
            }

            $previousUsage = (float) $usage->usage;
            $newUsage = $this->usageRepo->atomicIncrement($usage, $amount);

            if ($newUsage === null) {
                return false;
            }

            $this->logRepo->create([
                'subscription_id' => $subscription->id,
                'feature_id'      => $usage->feature_id,
                'operation'       => UsageOperation::Consume->value,
                'amount'          => $amount,
                'previous_usage'  => $previousUsage,
                'new_usage'       => $newUsage,
                'description'     => 'Usage increment',
            ]);

            $this->maybeFireLimitWarning($subscription, $usage, $feature, $previousUsage, $newUsage);

            return true;
        });
    }

    /**
     * Charge the provider first; if the charge is accepted, advance the
     * counter and write the usage log inside a DB transaction.
     *
     * Orphan-charge risk: if the provider succeeds but any of the DB
     * writes inside the transaction throws, we have charged the user
     * without recording the consumption. We catch that exception, log
     * critically with the idempotency key + amount so an operator can
     * reconcile via the provider's record of the charge, then re-throw
     * so the caller knows the call failed even though money moved.
     */
    protected function consumeMetered(
        Subscription $subscription,
        FeatureUsage $usage,
        Feature $feature,
        float $amount,
        ?string $idempotencyKey,
    ): bool {
        $snapshot = $this->snapshotRepo->findCurrentBySlug($subscription, $feature->slug);
        if (! $snapshot) {
            return false;
        }

        $unitPrice = $this->parseUnitPrice($snapshot->value);
        if ($unitPrice === null) {
            return false;
        }

        $subscriber = $this->resolveSubscriber($subscription);
        if (! $subscriber) {
            return false;
        }

        $currency = $this->subscriptionCurrency($subscription);
        $charge = round($amount * $unitPrice, 4);
        $key = $idempotencyKey ?? $this->generateMeteredIdempotencyKey($subscription, $feature);

        $charged = $this->resolveMeteredBilling($subscriber)->charge(
            $subscriber,
            $currency,
            $charge,
            [
                'idempotency_key' => $key,
                'subscription_id' => $subscription->id,
                'feature_id'      => $feature->id,
                'feature_slug'    => $feature->slug,
                'units'           => $amount,
                'unit_price'      => $unitPrice,
            ],
        );

        if (! $charged) {
            $this->dispatchAfterCommit(
                fn () => MeteredChargeRejected::dispatch(
                    $subscription,
                    $feature,
                    $amount,
                    $unitPrice,
                    $charge,
                    $currency,
                ),
            );

            return false;
        }

        try {
            return (bool) $this->db->connection()->transaction(function () use (
                $subscription,
                $usage,
                $feature,
                $amount,
                $unitPrice,
                $charge,
                $currency,
                $key,
            ) {
                $previousUsage = (float) $usage->usage;
                $newUsage = $this->usageRepo->atomicIncrement($usage, $amount);

                // limit_value is NULL for metered counters so the conditional
                // UPDATE will always succeed; null here means the row vanished
                // between findBySlug and the update, which shouldn't happen
                // outside extraordinary conditions.
                if ($newUsage === null) {
                    return false;
                }

                $this->logRepo->create([
                    'subscription_id' => $subscription->id,
                    'feature_id'      => $usage->feature_id,
                    'operation'       => UsageOperation::Consume->value,
                    'amount'          => $amount,
                    'previous_usage'  => $previousUsage,
                    'new_usage'       => $newUsage,
                    'description'     => sprintf(
                        'Metered charge: %s units × %s %s = %s %s',
                        rtrim(rtrim(number_format($amount, 4, '.', ''), '0'), '.'),
                        rtrim(rtrim(number_format($unitPrice, 4, '.', ''), '0'), '.'),
                        $currency,
                        rtrim(rtrim(number_format($charge, 4, '.', ''), '0'), '.'),
                        $currency,
                    ),
                    'metadata' => [
                        'unit_price'      => $unitPrice,
                        'amount'          => $charge,
                        'currency'        => $currency,
                        'idempotency_key' => $key,
                    ],
                ]);

                $this->eventStore->append($subscription, 'usage.metered_charged', [
                    'feature_id'   => $feature->id,
                    'feature_slug' => $feature->slug,
                    'units'        => $amount,
                    'unit_price'   => $unitPrice,
                    'amount'       => $charge,
                    'currency'     => $currency,
                ]);

                $this->dispatchAfterCommit(
                    fn () => MeteredCharged::dispatch(
                        $subscription,
                        $feature,
                        $amount,
                        $unitPrice,
                        $charge,
                        $currency,
                    ),
                );

                return true;
            });
        } catch (Throwable $e) {
            // The provider already debited the subscriber but we couldn't
            // record it on our side. Log loudly so operators can reconcile
            // against the provider using the idempotency key, then re-throw
            // so the caller knows the call did not complete cleanly.
            Log::critical('Metered charge succeeded but Tashil could not record it', [
                'idempotency_key' => $key,
                'subscription_id' => $subscription->id,
                'feature_id'      => $feature->id,
                'feature_slug'    => $feature->slug,
                'units'           => $amount,
                'unit_price'      => $unitPrice,
                'amount'          => $charge,
                'currency'        => $currency,
                'exception'       => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    protected function maybeFireLimitWarning(
        Subscription $subscription,
        FeatureUsage $usage,
        Feature $feature,
        float $previousUsage,
        float $newUsage,
    ): void {
        if ($usage->isUnlimited()) {
            return;
        }

        $limit = (float) $usage->limit_value;
        if ($limit <= 0) {
            return;
        }

        $threshold = 0.8 * $limit;
        if ($newUsage >= $threshold && $previousUsage < $threshold) {
            $this->dispatchAfterCommit(fn () => UsageLimitWarning::dispatch($subscription, $feature, $newUsage, $limit));
        }
    }

    /**
     * Pick the MeteredBilling implementation for this subscriber.
     *
     * Self-implementing models (e.g. `class User implements Subscribable,
     * MeteredBilling`) handle their own billing — no service binding
     * required. Otherwise we fall back to whatever is bound in the
     * container (the host's WalletMeteredBilling, or the default
     * NullMeteredBilling which throws on charge but returns safe
     * defaults on read paths).
     */
    protected function resolveMeteredBilling(Subscribable $subscriber): MeteredBilling
    {
        if ($subscriber instanceof MeteredBilling) {
            return $subscriber;
        }

        return app(MeteredBilling::class);
    }

    protected function resolveSubscriber(Subscription $subscription): ?Subscribable
    {
        $subscriber = $subscription->subscriber;

        return $subscriber instanceof Subscribable ? $subscriber : null;
    }

    protected function subscriptionCurrency(Subscription $subscription): string
    {
        $package = $subscription->package;

        return $package?->currency
            ?? (string) Config::get('tashil.currency', 'USD');
    }

    protected function parseUnitPrice(?string $value): ?float
    {
        if ($value === null || ! is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    protected function generateMeteredIdempotencyKey(Subscription $subscription, Feature $feature): string
    {
        return sprintf(
            'metered:%d:%d:%s',
            $subscription->id,
            $feature->id,
            (string) Str::uuid(),
        );
    }

    protected function hasExpiredPeriod(FeatureUsage $usage): bool
    {
        return $usage->period_end !== null && $usage->period_end->isPast();
    }
}
