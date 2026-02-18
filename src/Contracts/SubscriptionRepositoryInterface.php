<?php

namespace Foysal50x\Tashil\Contracts;

use Foysal50x\Tashil\Models\Package;
use Foysal50x\Tashil\Models\Subscription;
use Illuminate\Database\Eloquent\Model;

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
    public function findValidForSubscriber(Model $subscriber): ?Subscription;

    /**
     * Check if a subscriber has a valid subscription to a given package (or slug).
     */
    public function subscriberHasValidSubscription(Model $subscriber, Package|string|null $package = null): bool;

    /**
     * Find a cancelled-but-not-expired subscription for a subscriber.
     */
    public function findCancelledResumable(Model $subscriber): ?Subscription;

    public function getExpiringSubscriptions(\DateTimeInterface $date, ?bool $autoRenew = null): \Illuminate\Database\Eloquent\Collection;

    /**
     * Create subscription items (features) from a package.
     */
    public function syncFeatureItems(Subscription $subscription, Package $package): void;

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
