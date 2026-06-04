<?php

namespace Foysal50x\Tashil\Contracts;

use Foysal50x\Tashil\Models\Package;
use Foysal50x\Tashil\Models\Subscription;
use Illuminate\Database\Eloquent\Collection;

interface SubscriptionRepositoryInterface
{
    /**
     * Create a new subscription.
     */
    public function create(array $data): Subscription;

    /**
     * Update a subscription.
     */
    public function update(Subscription $subscription, array $data): Subscription;

    /**
     * Find a subscription by ID.
     */
    public function findById(int $id): ?Subscription;

    /**
     * Get the current valid subscription for a subscriber.
     */
    public function findValidForSubscriber(Subscribable $subscriber): ?Subscription;

    /**
     * Check if a subscriber has a valid subscription to a given package (or slug).
     */
    public function subscriberHasValidSubscription(Subscribable $subscriber, Package|string|null $package = null): bool;

    /**
     * Whether the subscriber already has a non-terminal subscription
     * (active / on_trial / pending / past_due / pending_cancellation /
     * paused / suspended). Used to prevent accidental duplicate
     * subscriptions on subscribe().
     */
    public function hasLiveSubscription(Subscribable $subscriber): bool;

    /**
     * Find a cancelled-but-not-expired subscription for a subscriber.
     */
    public function findCancelledResumable(Subscribable $subscriber): ?Subscription;

    /**
     * Subscriptions whose current_period_end has elapsed and whose
     * status still allows renewal (Active, OnTrial after conversion).
     */
    public function dueForRenewal(\DateTimeInterface $moment): Collection;

    /**
     * Subscriptions whose access window has expired — Active rows past
     * ends_at, or PendingCancellation rows past cancellation_effective_at.
     */
    public function dueForExpiration(\DateTimeInterface $moment): Collection;

    /**
     * Trials whose trial_ends_at has elapsed without conversion.
     */
    public function dueForTrialExpiration(\DateTimeInterface $moment): Collection;

    /**
     * Active trials ending within $warnDays.
     */
    public function trialsEndingSoon(\DateTimeInterface $now, int $warnDays): Collection;

    /**
     * Subscriptions whose pending_change_at has elapsed.
     */
    public function dueForPendingChange(\DateTimeInterface $moment): Collection;

    /**
     * Create the subscription_features snapshot and feature_usages counter
     * rows from a package's feature pivot.
     */
    public function syncFeatures(Subscription $subscription, Package $package): void;

    /**
     * In-place plan change on the SAME subscription: supersede the current
     * feature snapshots, write new ones from $package, and reconcile the
     * counters — carrying existing usage forward when $carryUsage is true
     * (the cap + reset cadence are updated to the new plan; the usage value
     * is kept). Counters for features the new plan drops are left in place
     * (no current snapshot ⇒ gating denies) and never deleted.
     */
    public function resyncFeatures(Subscription $subscription, Package $package, bool $carryUsage = true): void;

    /**
     * Count active subscriptions (active + on_trial).
     */
    public function activeCount(): int;

    /**
     * Count churned subscriptions in a given period.
     */
    public function churnedCount(\DateTimeInterface $since): int;

    /**
     * Count total subscriptions that existed in a given period.
     */
    public function totalCountInPeriod(\DateTimeInterface $since): int;

    /**
     * Calculate MRR (Monthly Recurring Revenue).
     */
    public function calculateMRR(): float;

    /**
     * Count all subscriptions.
     */
    public function totalCount(): int;

    /**
     * Count subscriptions grouped by status.
     *
     * @return array<string, int>
     */
    public function countByStatus(): array;

    /**
     * Calculate trial-to-active conversion rate (percentage).
     */
    public function trialConversionRate(): float;

    /**
     * Count new subscriptions per month for the last N months.
     *
     * @return array<int, array{month: string, count: int}>
     */
    public function newSubscriptionsPerPeriod(int $months = 12): array;

    /**
     * Count active subscribers grouped by package.
     *
     * @return array<int, array{package_id: int, package_name: string, count: int}>
     */
    public function subscribersByPackage(): array;

    /**
     * Total revenue per package (from paid invoices).
     *
     * @return array<int, array{package_id: int, package_name: string, revenue: float}>
     */
    public function revenueByPackage(): array;

    /**
     * Churn rate trend over the last N months.
     * Uses batch queries (2 queries total) instead of 2×N queries.
     *
     * @return array<int, array{month: string, churn_rate: float}>
     */
    public function churnTrend(int $months = 12, int $windowDays = 30): array;

    /**
     * Batched dashboard stats in a single query.
     *
     * @return array{total: int, active: int, on_trial: int, cancelled: int, expired: int, trial_conversion_rate: float, mrr: float}
     */
    public function dashboardStats(): array;

    /**
     * Per-package subscription analytics (grouped by package).
     *
     * Returns total subscribers, active subscribers, MRR, trial conversion rate,
     * and cancelled count for each package — in a single optimized query.
     *
     * @return array<int, array{
     *     package_id: int,
     *     package_name: string,
     *     total_subscribers: int,
     *     active_subscribers: int,
     *     cancelled_count: int,
     *     mrr: float,
     *     average_mrr: float,
     *     trial_conversion_rate: float,
     *     total_revenue: float,
     * }>
     */
    public function analyticsByPackage(): array;
}
