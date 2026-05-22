<?php

namespace Foysal50x\Tashil\Services;

use Foysal50x\Tashil\Contracts\FeatureUsageRepositoryInterface;
use Foysal50x\Tashil\Contracts\SubscriptionFeatureRepositoryInterface;
use Foysal50x\Tashil\Contracts\UsageLogRepositoryInterface;
use Foysal50x\Tashil\Enums\FeatureType;
use Foysal50x\Tashil\Enums\UsageOperation;
use Foysal50x\Tashil\Events\UsageLimitWarning;
use Foysal50x\Tashil\Events\UsageReset;
use Foysal50x\Tashil\Managers\DatabaseManager;
use Foysal50x\Tashil\Models\Feature;
use Foysal50x\Tashil\Models\FeatureUsage;
use Foysal50x\Tashil\Models\Subscription;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

class UsageService
{
    public function __construct(
        protected DatabaseManager $db,
        protected FeatureUsageRepositoryInterface $usageRepo,
        protected SubscriptionFeatureRepositoryInterface $snapshotRepo,
        protected UsageLogRepositoryInterface $logRepo,
        protected EventStore $eventStore,
    ) {}

    /**
     * Atomically increment usage. Returns false if the increment would
     * exceed the configured limit — the row is not modified in that case.
     */
    public function increment(Subscription $subscription, string $featureSlug, float $amount = 1): bool
    {
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

        $previousUsage = (float) $usage->usage;
        $newUsage = $this->usageRepo->atomicIncrement($usage, $amount);

        if ($newUsage === null) {
            // Limit would have been exceeded — increment rejected.
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
    }

    /**
     * Check whether a feature can be used right now.
     */
    public function check(Subscription $subscription, string $featureSlug, float $amount = 1): bool
    {
        if (! $subscription->isValid()) {
            return false;
        }

        $snapshot = $this->snapshotRepo->findCurrentBySlug($subscription, $featureSlug);
        if (! $snapshot) {
            return false;
        }

        // Feature can be disabled at the catalog level even though a snapshot exists.
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

            return ((float) $usage->usage + $amount) <= (float) $usage->limit_value;
        }

        // Consumable and Enum: access is granted as long as a snapshot exists.
        return true;
    }

    /**
     * Absolute usage report — used for storage-style features where the
     * caller knows the current total (e.g. bytes used).
     */
    public function reportStorage(Subscription $subscription, string $featureSlug, float $amount): bool
    {
        $usage = $this->usageRepo->findBySlug($subscription, $featureSlug, withFeature: true);
        if (! $usage) {
            return false;
        }

        $feature = $usage->feature;
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
    }

    public function resetUsage(Subscription $subscription, string $featureSlug): bool
    {
        $usage = $this->usageRepo->findBySlug($subscription, $featureSlug, withFeature: true);
        if (! $usage) {
            return false;
        }

        $this->db->connection()->transaction(function () use ($subscription, $usage) {
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
        });

        return true;
    }

    public function resetAllUsage(Subscription $subscription): void
    {
        $this->usageRepo->resetAllUsage($subscription);
    }

    // ── Internal ────────────────────────────────────────────────

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

    protected function dispatchAfterCommit(\Closure $dispatcher): void
    {
        if (Config::get('tashil.events.async', true)) {
            DB::afterCommit($dispatcher);

            return;
        }

        $dispatcher();
    }
}
