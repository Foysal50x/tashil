<?php

namespace Foysal50x\Tashil\Traits;

use Foysal50x\Tashil\Contracts\InvoiceRepositoryInterface;
use Foysal50x\Tashil\Contracts\SubscriptionItemRepositoryInterface;
use Foysal50x\Tashil\Contracts\SubscriptionRepositoryInterface;
use Foysal50x\Tashil\Contracts\UsageLogRepositoryInterface;
use Foysal50x\Tashil\Models\Package;
use Foysal50x\Tashil\Models\Subscription;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Provides comprehensive subscription functionality to any Eloquent model.
 *
 * Usage:
 *   class User extends Authenticatable { use HasSubscriptions; }
 *   class Team extends Model           { use HasSubscriptions; }
 */
trait HasSubscriptions
{
    /**
     * Cached active subscription for the current request lifecycle.
     * Avoids redundant "find valid subscription" queries when multiple
     * feature/usage methods are called on the same model instance.
     */
    protected ?Subscription $resolvedSubscription = null;

    protected bool $subscriptionResolved = false;

    // ── Relationships ────────────────────────────────────────────

    /**
     * All subscriptions for this model.
     */
    public function subscriptions(): MorphMany
    {
        return $this->morphMany(Subscription::class, 'subscriber');
    }

    // ── Active subscription ─────────────────────────────────────

    /**
     * Get the currently active/valid subscription (active or on trial).
     *
     * Always hits the database — use `loadSubscription()` for cached access.
     */
    public function subscription(): ?Subscription
    {
        return $this->subscriptionRepo()->findValidForSubscriber($this);
    }

    /**
     * Get the active subscription, cached for the model's lifetime.
     *
     * This is used internally by all feature/usage methods to avoid
     * repeated queries when multiple methods are called on the same instance.
     */
    public function loadSubscription(): ?Subscription
    {
        if (! $this->subscriptionResolved) {
            $this->resolvedSubscription = $this->subscription();
            $this->subscriptionResolved = true;
        }

        return $this->resolvedSubscription;
    }

    /**
     * Clear the cached subscription so the next call re-fetches from DB.
     *
     * Call this after subscribe/cancel/switch operations to ensure
     * subsequent feature checks reflect the new state.
     */
    public function clearSubscriptionCache(): static
    {
        $this->resolvedSubscription = null;
        $this->subscriptionResolved = false;

        return $this;
    }

    /**
     * Check if the model has an active or on-trial subscription.
     */
    public function subscribed(): bool
    {
        return $this->subscriptionRepo()->subscriberHasValidSubscription($this);
    }

    /**
     * Check if the model is subscribed to a specific package (by model or slug).
     */
    public function subscribedTo(Package|string $package): bool
    {
        return $this->subscriptionRepo()->subscriberHasValidSubscription($this, $package);
    }

    /**
     * Check if the model is on a specific plan by slug.
     */
    public function onPlan(string $slug): bool
    {
        return $this->subscribedTo($slug);
    }

    // ── Trial ───────────────────────────────────────────────────

    /**
     * Check if the model is currently on a trial.
     */
    public function onTrial(): bool
    {
        return $this->loadSubscription()?->isOnTrial() ?? false;
    }

    // ── Subscribe / Cancel / Resume / Switch ────────────────────

    /**
     * Subscribe this model to a package.
     */
    public function subscribe(Package $package, bool $withTrial = false): Subscription
    {
        $this->clearSubscriptionCache();

        return app('tashil')->subscription()->subscribe($this, $package, $withTrial);
    }

    /**
     * Cancel the active subscription.
     *
     * @param  bool         $immediate  Cancel immediately or at end of period
     * @param  string|null  $reason     Cancellation reason
     */
    public function cancelSubscription(bool $immediate = false, ?string $reason = null): ?Subscription
    {
        $subscription = $this->loadSubscription();

        if (! $subscription) {
            return null;
        }

        $this->clearSubscriptionCache();

        return app('tashil')->subscription()->cancel($subscription, $immediate, $reason);
    }

    /**
     * Resume a cancelled subscription (if not yet expired).
     */
    public function resumeSubscription(): ?Subscription
    {
        $subscription = $this->subscriptionRepo()->findCancelledResumable($this);

        if (! $subscription) {
            return null;
        }

        $this->clearSubscriptionCache();

        return app('tashil')->subscription()->resume($subscription);
    }

    /**
     * Switch the active subscription to a different package.
     */
    public function switchPlan(Package $newPackage): ?Subscription
    {
        $subscription = $this->loadSubscription();

        if (! $subscription) {
            return null;
        }

        $this->clearSubscriptionCache();

        return app('tashil')->subscription()->switchPlan($subscription, $newPackage);
    }

    // ── Feature access ──────────────────────────────────────────

    /**
     * Check if the model has access to a given feature (by slug).
     */
    public function hasFeature(string $featureSlug): bool
    {
        $subscription = $this->loadSubscription();

        if (! $subscription) {
            return false;
        }

        return app('tashil')->usage()->check($subscription, $featureSlug);
    }

    /**
     * Get the configured value for a feature on the active subscription.
     */
    public function featureValue(string $featureSlug): mixed
    {
        $subscription = $this->loadSubscription();

        if (! $subscription) {
            return null;
        }

        $item = $this->itemRepo()->findByFeatureSlug($subscription, $featureSlug);

        return $item?->value;
    }

    /**
     * Get the current usage for a feature.
     */
    public function featureUsage(string $featureSlug): int
    {
        $subscription = $this->loadSubscription();

        if (! $subscription) {
            return 0;
        }

        $item = $this->itemRepo()->findByFeatureSlug($subscription, $featureSlug);

        return $item?->usage ?? 0;
    }

    /**
     * Get the remaining quota for a feature (null = unlimited).
     */
    public function featureRemaining(string $featureSlug): ?float
    {
        $subscription = $this->loadSubscription();

        if (! $subscription) {
            return null;
        }

        $item = $this->itemRepo()->findByFeatureSlug($subscription, $featureSlug);

        return $item?->remaining();
    }

    /**
     * Get daily usage breakdown for a feature on the active subscription.
     *
     * @return array<int, array{date: string, total: int}>
     */
    public function dailyUsageFor(string $featureSlug, int $days = 30): array
    {
        $subscription = $this->loadSubscription();

        if (! $subscription) {
            return [];
        }

        $item = $this->itemRepo()->findByFeatureSlug($subscription, $featureSlug);

        if (! $item) {
            return [];
        }

        return $this->usageLogRepo()->getDailyUsage($subscription->id, $item->feature_id, $days);
    }

    /**
     * Increment usage for a feature.
     */
    public function useFeature(string $featureSlug, float $amount = 1): bool
    {
        $subscription = $this->loadSubscription();

        if (! $subscription) {
            return false;
        }

        return app('tashil')->usage()->increment($subscription, $featureSlug, $amount);
    }

    // ── Invoices ────────────────────────────────────────────────

    /**
     * Get all invoices across all subscriptions.
     */
    public function invoices(): MorphMany
    {
        return $this->subscriptions();
    }

    /**
     * Get all invoices as a collection (flattened from all subscriptions).
     */
    public function allInvoices()
    {
        return $this->invoiceRepo()->findBySubscriptionIds(
            $this->subscriptions()->pluck('id')->toArray()
        );
    }

    // ── Repository resolvers ────────────────────────────────────

    protected function subscriptionRepo(): SubscriptionRepositoryInterface
    {
        return app(SubscriptionRepositoryInterface::class);
    }

    protected function itemRepo(): SubscriptionItemRepositoryInterface
    {
        return app(SubscriptionItemRepositoryInterface::class);
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
