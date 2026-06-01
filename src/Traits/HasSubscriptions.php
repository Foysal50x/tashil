<?php

namespace Foysal50x\Tashil\Traits;

use Foysal50x\Tashil\Contracts\FeatureUsageRepositoryInterface;
use Foysal50x\Tashil\Contracts\InvoiceRepositoryInterface;
use Foysal50x\Tashil\Contracts\SubscriptionFeatureRepositoryInterface;
use Foysal50x\Tashil\Contracts\SubscriptionRepositoryInterface;
use Foysal50x\Tashil\Contracts\UsageLogRepositoryInterface;
use Foysal50x\Tashil\Models\Package;
use Foysal50x\Tashil\Models\Subscription;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Provides subscription functionality to any Eloquent model that
 * implements the Subscribable contract.
 *
 *   class User extends Authenticatable implements Subscribable
 *   {
 *       use HasSubscriptions;
 *   }
 *
 * Hosts override resolveSubscription() to change which subscription
 * represents the active one (multi-sub scenarios, tenant scoping, etc.).
 * Everything in this trait that reaches for "the subscription" goes
 * through loadSubscription(), which calls resolveSubscription() once
 * per request lifecycle and caches the result on the instance.
 */
trait HasSubscriptions
{
    protected ?Subscription $resolvedSubscription = null;

    protected bool $subscriptionResolved = false;

    public function subscriptions(): MorphMany
    {
        return $this->morphMany(Subscription::class, 'subscriber');
    }

    /**
     * Subscribable: primary key used in subscription lookups.
     */
    public function getSubscriberKey(): int|string
    {
        return $this->getKey();
    }

    /**
     * Subscribable: polymorphic morph type written to subscriptions.subscriber_type.
     */
    public function getSubscriberType(): string
    {
        return $this->getMorphClass();
    }

    /**
     * Subscribable: default subscription resolver — the latest valid
     * subscription. Override in your model for custom selection logic
     * (e.g. preferring a specific package or tenant scope).
     */
    public function resolveSubscription(): ?Subscription
    {
        return $this->subscriptionRepo()->findValidForSubscriber($this);
    }

    /**
     * Direct DB lookup of the currently valid subscription. Skips the
     * request-scoped cache — use loadSubscription() when you want
     * memoization across multiple calls in the same request.
     */
    public function subscription(): ?Subscription
    {
        return $this->resolveSubscription();
    }

    /**
     * Memoized accessor used by every feature/lifecycle helper below.
     * The first call resolves via resolveSubscription(); subsequent
     * calls return the cached instance until clearSubscriptionCache().
     */
    public function loadSubscription(): ?Subscription
    {
        if (! $this->subscriptionResolved) {
            $this->resolvedSubscription = $this->resolveSubscription();
            $this->subscriptionResolved = true;
        }

        return $this->resolvedSubscription;
    }

    public function clearSubscriptionCache(): static
    {
        $this->resolvedSubscription = null;
        $this->subscriptionResolved = false;

        return $this;
    }

    public function subscribed(): bool
    {
        return $this->subscriptionRepo()->subscriberHasValidSubscription($this);
    }

    public function subscribedTo(Package|string $package): bool
    {
        return $this->subscriptionRepo()->subscriberHasValidSubscription($this, $package);
    }

    public function onPlan(string $slug): bool
    {
        return $this->subscribedTo($slug);
    }

    public function onTrial(): bool
    {
        return $this->loadSubscription()?->isOnTrial() ?? false;
    }

    public function paused(): bool
    {
        return $this->loadSubscription()?->isPaused() ?? false;
    }

    public function pendingChange(): ?Package
    {
        $sub = $this->loadSubscription();
        if (! $sub || ! $sub->hasPendingChange()) {
            return null;
        }

        return $sub->pendingPackage;
    }

    public function subscribe(Package $package, bool $withTrial = false): Subscription
    {
        $this->clearSubscriptionCache();

        return app('tashil')->subscription()->subscribe($this, $package, $withTrial);
    }

    public function cancelSubscription(bool $immediate = false, ?string $reason = null): ?Subscription
    {
        $subscription = $this->loadSubscription();
        if (! $subscription) {
            return null;
        }

        $this->clearSubscriptionCache();

        return app('tashil')->subscription()->cancel($subscription, $immediate, $reason);
    }

    public function resumeSubscription(): ?Subscription
    {
        $subscription = $this->subscriptionRepo()->findCancelledResumable($this);
        if (! $subscription) {
            return null;
        }

        $this->clearSubscriptionCache();

        return app('tashil')->subscription()->resume($subscription);
    }

    public function switchPlan(Package $newPackage): ?Subscription
    {
        $subscription = $this->loadSubscription();
        if (! $subscription) {
            return null;
        }

        $this->clearSubscriptionCache();

        return app('tashil')->subscription()->switchPlan($subscription, $newPackage);
    }

    public function pauseSubscription(): ?Subscription
    {
        $subscription = $this->loadSubscription();
        if (! $subscription) {
            return null;
        }

        $this->clearSubscriptionCache();

        return app('tashil')->subscription()->pause($subscription);
    }

    public function unpauseSubscription(): ?Subscription
    {
        $subscription = $this->loadSubscription();
        if (! $subscription) {
            return null;
        }

        $this->clearSubscriptionCache();

        return app('tashil')->subscription()->unpause($subscription);
    }

    public function scheduleDowngrade(Package $target): ?Subscription
    {
        $subscription = $this->loadSubscription();
        if (! $subscription) {
            return null;
        }

        $this->clearSubscriptionCache();

        return app('tashil')->subscription()->scheduleDowngrade($subscription, $target);
    }

    public function hasFeature(string $featureSlug): bool
    {
        $subscription = $this->loadSubscription();
        if (! $subscription) {
            return false;
        }

        return app('tashil')->usage()->check($subscription, $featureSlug);
    }

    public function featureValue(string $featureSlug): mixed
    {
        $subscription = $this->loadSubscription();
        if (! $subscription) {
            return null;
        }

        $snapshot = $this->snapshotRepo()->findCurrentBySlug($subscription, $featureSlug);

        return $snapshot?->value;
    }

    public function featureUsage(string $featureSlug): float
    {
        $subscription = $this->loadSubscription();
        if (! $subscription) {
            return 0.0;
        }

        $usage = $this->usageRepo()->findBySlug($subscription, $featureSlug);

        return (float) ($usage?->usage ?? 0);
    }

    public function featureRemaining(string $featureSlug): ?float
    {
        $subscription = $this->loadSubscription();
        if (! $subscription) {
            return null;
        }

        $usage = $this->usageRepo()->findBySlug($subscription, $featureSlug);

        return $usage?->remaining();
    }

    public function dailyUsageFor(string $featureSlug, int $days = 30): array
    {
        $subscription = $this->loadSubscription();
        if (! $subscription) {
            return [];
        }

        $usage = $this->usageRepo()->findBySlug($subscription, $featureSlug);
        if (! $usage) {
            return [];
        }

        return $this->usageLogRepo()->getDailyUsage($subscription->id, $usage->feature_id, $days);
    }

    /**
     * Consume a feature.
     *
     * $idempotencyKey: pass a stable, caller-supplied token (e.g. the
     * request ID, a job UUID, or "{userId}:{actionId}") to dedupe
     * app-level retries — the same key reaching MeteredBilling::charge
     * twice will be treated by a well-behaved provider as the same
     * operation. When null, Tashil generates a fresh UUID per call,
     * which only protects against provider-internal retries within a
     * single attempt.
     */
    public function useFeature(string $featureSlug, float $amount = 1, ?string $idempotencyKey = null): bool
    {
        $subscription = $this->loadSubscription();
        if (! $subscription) {
            return false;
        }

        return app('tashil')->usage()->increment($subscription, $featureSlug, $amount, $idempotencyKey);
    }

    public function reportStorage(string $featureSlug, float $amount): bool
    {
        $subscription = $this->loadSubscription();
        if (! $subscription) {
            return false;
        }

        return app('tashil')->usage()->reportStorage($subscription, $featureSlug, $amount);
    }

    /**
     * All invoices across this subscriber's subscriptions.
     */
    public function invoices(): Collection
    {
        return $this->invoiceRepo()->findBySubscriptionIds(
            $this->subscriptions()->pluck('id')->toArray(),
        );
    }

    protected function subscriptionRepo(): SubscriptionRepositoryInterface
    {
        return app(SubscriptionRepositoryInterface::class);
    }

    protected function usageRepo(): FeatureUsageRepositoryInterface
    {
        return app(FeatureUsageRepositoryInterface::class);
    }

    protected function snapshotRepo(): SubscriptionFeatureRepositoryInterface
    {
        return app(SubscriptionFeatureRepositoryInterface::class);
    }

    protected function invoiceRepo(): InvoiceRepositoryInterface
    {
        return app(InvoiceRepositoryInterface::class);
    }

    protected function usageLogRepo(): UsageLogRepositoryInterface
    {
        return app(UsageLogRepositoryInterface::class);
    }
}
