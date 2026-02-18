<?php

namespace Foysal50x\Tashil\Services;

use Foysal50x\Tashil\Contracts\InvoiceRepositoryInterface;
use Foysal50x\Tashil\Contracts\SubscriptionRepositoryInterface;
use Foysal50x\Tashil\Contracts\UsageLogRepositoryInterface;
use Foysal50x\Tashil\Models\Subscription;

class AnalyticsService
{
    public function __construct(
        protected SubscriptionRepositoryInterface $subscriptionRepo,
        protected UsageLogRepositoryInterface $usageLogRepo,
        protected InvoiceRepositoryInterface $invoiceRepo,
    ) {}

    // ── Usage ───────────────────────────────────────────────────

    /**
     * Get total usage per day for a subscription and feature.
     *
     * @return array<int, array{date: string, total: int}>
     */
    public function getDailyUsage(Subscription $subscription, int $featureId, int $days = 30): array
    {
        return $this->usageLogRepo->getDailyUsage($subscription->id, $featureId, $days);
    }

    // ── Subscription Metrics ────────────────────────────────────

    /**
     * Total number of subscriptions (all statuses).
     */
    public function totalSubscriptionCount(): int
    {
        return $this->subscriptionRepo->totalCount();
    }

    /**
     * Count of active subscriptions (active + on_trial).
     */
    public function activeSubscriptionCount(): int
    {
        return $this->subscriptionRepo->activeCount();
    }

    /**
     * Subscription count grouped by status.
     *
     * @return array<string, int>  e.g. ['active' => 120, 'on_trial' => 30, ...]
     */
    public function subscriptionCountByStatus(): array
    {
        return $this->subscriptionRepo->countByStatus();
    }

    /**
     * Active subscribers distributed by package.
     *
     * @return array<int, array{package_id: int, package_name: string, count: int}>
     */
    public function subscribersByPackage(): array
    {
        return $this->subscriptionRepo->subscribersByPackage();
    }

    /**
     * Trial-to-active conversion rate (percentage).
     */
    public function trialConversionRate(): float
    {
        return $this->subscriptionRepo->trialConversionRate();
    }

    /**
     * New subscriptions per month for the last N months (chart data).
     *
     * @return array<int, array{month: string, count: int}>
     */
    public function subscriptionGrowth(int $months = 12): array
    {
        return $this->subscriptionRepo->newSubscriptionsPerPeriod($months);
    }

    // ── Revenue & Billing Metrics ───────────────────────────────

    /**
     * Calculate MRR (Monthly Recurring Revenue).
     */
    public function calculateMRR(): float
    {
        return $this->subscriptionRepo->calculateMRR();
    }

    /**
     * Average Revenue Per User (ARPU).
     * Calculated as MRR / active subscription count.
     */
    public function averageRevenuePerUser(): float
    {
        $activeCount = $this->subscriptionRepo->activeCount();

        if ($activeCount === 0) {
            return 0.0;
        }

        return round($this->subscriptionRepo->calculateMRR() / $activeCount, 2);
    }

    /**
     * Lifetime total revenue (sum of all paid invoices).
     */
    public function totalRevenue(): float
    {
        return $this->invoiceRepo->totalRevenue();
    }

    /**
     * Monthly revenue totals for the last N months (chart data).
     *
     * @return array<int, array{month: string, revenue: float}>
     */
    public function revenueByPeriod(int $months = 12): array
    {
        return $this->invoiceRepo->revenueByPeriod($months);
    }

    /**
     * Revenue generated per package (from paid invoices).
     *
     * @return array<int, array{package_id: int, package_name: string, revenue: float}>
     */
    public function revenueByPackage(): array
    {
        return $this->subscriptionRepo->revenueByPackage();
    }

    // ── Invoice Metrics ─────────────────────────────────────────

    /**
     * Count of pending invoices.
     */
    public function pendingInvoiceCount(): int
    {
        return $this->invoiceRepo->pendingCount();
    }

    /**
     * Count of overdue invoices (pending + past due date).
     */
    public function overdueInvoiceCount(): int
    {
        return $this->invoiceRepo->overdueCount();
    }

    // ── Churn ───────────────────────────────────────────────────

    /**
     * Churn rate for the last N days (percentage).
     * Churn = cancelled subscriptions / total subscriptions that existed in the period.
     */
    public function churnRate(int $days = 30): float
    {
        $since = now()->subDays($days);

        $total = $this->subscriptionRepo->totalCountInPeriod($since);

        if ($total === 0) {
            return 0.0;
        }

        $churned = $this->subscriptionRepo->churnedCount($since);

        return round(($churned / $total) * 100, 2);
    }

    /**
     * Churn rate trend over the last N months (chart data).
     * Each month's churn is calculated over a window of $windowDays.
     *
     * Uses batch queries — only 2 DB queries instead of 2×N.
     *
     * @return array<int, array{month: string, churn_rate: float}>
     */
    public function churnTrend(int $months = 12, int $windowDays = 30): array
    {
        return $this->subscriptionRepo->churnTrend($months, $windowDays);
    }

    // ── Dashboard Summary ───────────────────────────────────────

    /**
     * All-in-one dashboard KPI summary.
     *
     * Uses batch queries — only 2 DB queries instead of ~10.
     *
     * @return array{
     *     total_subscriptions: int,
     *     active_subscriptions: int,
     *     subscriptions_by_status: array<string, int>,
     *     mrr: float,
     *     arpu: float,
     *     total_revenue: float,
     *     churn_rate: float,
     *     trial_conversion_rate: float,
     *     pending_invoices: int,
     *     overdue_invoices: int,
     * }
     */
    public function dashboardSummary(): array
    {
        $subStats = $this->subscriptionRepo->dashboardStats();
        $invStats = $this->invoiceRepo->dashboardStats();

        $active = $subStats['active'];
        $arpu   = $active > 0 ? round($subStats['mrr'] / $active, 2) : 0.0;

        $denominator = $subStats['total'] - $subStats['expired'];
        $churnRate   = $denominator > 0
            ? round(($subStats['cancelled'] / $denominator) * 100, 2)
            : 0.0;

        return [
            'total_subscriptions'     => $subStats['total'],
            'active_subscriptions'    => $active,
            'subscriptions_by_status' => [
                'active'    => $subStats['active'],
                'on_trial'  => $subStats['on_trial'],
                'cancelled' => $subStats['cancelled'],
                'expired'   => $subStats['expired'],
            ],
            'mrr'                     => $subStats['mrr'],
            'arpu'                    => $arpu,
            'total_revenue'           => $invStats['total_revenue'],
            'churn_rate'              => $churnRate,
            'trial_conversion_rate'   => $subStats['trial_conversion_rate'],
            'pending_invoices'        => $invStats['pending_count'],
            'overdue_invoices'        => $invStats['overdue_count'],
        ];
    }

    // ── Per-Package Analytics ───────────────────────────────────

    /**
     * Comprehensive analytics grouped by package.
     *
     * Merges subscription metrics (from a single grouped query) with
     * invoice metrics (from a second grouped query) for each package.
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
     *     pending_invoices: int,
     *     overdue_invoices: int,
     * }>
     */
    public function packageAnalytics(): array
    {
        $subStats = $this->subscriptionRepo->analyticsByPackage();
        $invStats = collect($this->invoiceRepo->invoiceStatsByPackage())
            ->keyBy('package_id');

        return array_map(function (array $pkg) use ($invStats) {
            $inv = $invStats->get($pkg['package_id']);

            return array_merge($pkg, [
                'pending_invoices' => $inv['pending_count'] ?? 0,
                'overdue_invoices' => $inv['overdue_count'] ?? 0,
            ]);
        }, $subStats);
    }

}
