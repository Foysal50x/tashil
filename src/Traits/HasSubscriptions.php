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
 * Provides subscription functionality to any Eloquent model.
 *
 * Usage:
 *   class User extends Authenticatable { use HasSubscriptions; }
 *   class Team extends Model           { use HasSubscriptions; }
 */
trait HasSubscriptions
{
    protected ?Subscription $resolvedSubscription = null;

    protected bool $subscriptionResolved = false;

    // ── Relationships ────────────────────────────────────────────

    public function subscriptions(): MorphMany
    {
        return $this->morphMany(Subscription::class, 'subscriber');
    }

    // ── Active subscription ─────────────────────────────────────

    public function subscription(): ?Subscription
    {
        return $this->subscriptionRepo()->findValidForSubscriber($this);
    }

    public function loadSubscription(): ?Subscription
    {
        if (! $this->subscriptionResolved) {
            $this->resolvedSubscription = $this->subscription();
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

    // ── Trial / state ───────────────────────────────────────────

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

    // ── Subscribe / Cancel / Resume / Switch / Pause ────────────

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

    // ── Feature access ──────────────────────────────────────────

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

    public function useFeature(string $featureSlug, float $amount = 1): bool
    {
        $subscription = $this->loadSubscription();
        if (! $subscription) {
            return false;
        }

        return app('tashil')->usage()->increment($subscription, $featureSlug, $amount);
    }

    public function reportStorage(string $featureSlug, float $amount): bool
    {
        $subscription = $this->loadSubscription();
        if (! $subscription) {
            return false;
        }

        return app('tashil')->usage()->reportStorage($subscription, $featureSlug, $amount);
    }

    // ── Invoices ────────────────────────────────────────────────

    /**
     * All invoices across this subscriber's subscriptions.
     */
    public function invoices(): Collection
    {
        return $this->invoiceRepo()->findBySubscriptionIds(
            $this->subscriptions()->pluck('id')->toArray(),
        );
    }

    // ── Repository resolvers ────────────────────────────────────

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
