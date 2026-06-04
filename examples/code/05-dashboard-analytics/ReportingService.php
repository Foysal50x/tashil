<?php

declare(strict_types=1);

namespace App\Services\Reporting;

use Foysal50x\Tashil\Enums\InvoiceStatus;
use Foysal50x\Tashil\Facades\Tashil;
use Foysal50x\Tashil\Models\Invoice;
use Foysal50x\Tashil\Models\Subscription;
use Foysal50x\Tashil\Support\Query\DateFmt;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * DASHBOARD REPORTING — date-range, due, and day/week/month grouping.
 *
 * Tashil's AnalyticsService gives you all-time / rolling KPIs out of the box
 * (dashboardSummary(), MRR, churn, revenueByPeriod(months)). What it does NOT
 * give you is an ARBITRARY date window or week-bucketing — those are app
 * concerns, so this service shows how to build them on top of the package
 * models, staying cross-database by reusing the package's own DateFmt helper.
 *
 * Two layers:
 *   1. Built-in KPIs  → Tashil::analytics()->dashboardSummary()
 *   2. Windowed stats → direct queries over Invoice / Subscription, filtered by
 *      a [from, to] range and grouped by day / week / month.
 */
class ReportingService
{
    /**
     * Layer 1 — the package's all-in-one KPI snapshot (≈2 queries).
     * total/active/by-status subs, MRR, ARPU, total revenue, churn %,
     * trial conversion %, pending & overdue invoice counts.
     */
    public function kpis(): array
    {
        return Tashil::analytics()->dashboardSummary();
    }

    // ─── Windowed scalars ────────────────────────────────────────────────────

    /** Revenue (sum of PAID invoice amounts) settled within [from, to]. */
    public function revenueBetween(Carbon $from, Carbon $to): float
    {
        return (float) Invoice::query()
            ->where('status', InvoiceStatus::Paid)
            ->whereBetween('paid_at', [$from, $to])
            ->sum('amount');
    }

    /** New subscriptions created within [from, to]. */
    public function newSubscriptionsBetween(Carbon $from, Carbon $to): int
    {
        return Subscription::query()
            ->whereBetween('created_at', [$from, $to])
            ->count();
    }

    /** Subscriptions cancelled within [from, to] (grace or immediate). */
    public function cancellationsBetween(Carbon $from, Carbon $to): int
    {
        return Subscription::query()
            ->whereNotNull('cancelled_at')
            ->whereBetween('cancelled_at', [$from, $to])
            ->count();
    }

    /** Trials that converted to paid within [from, to]. */
    public function conversionsBetween(Carbon $from, Carbon $to): int
    {
        return Subscription::query()
            ->whereNotNull('trial_converted_at')
            ->whereBetween('trial_converted_at', [$from, $to])
            ->count();
    }

    // ─── Time series: group by day / week / month ────────────────────────────

    /**
     * Revenue series across [from, to], bucketed by $granularity.
     *
     * 'day'   → DateFmt 'Y-m-d'   (e.g. 2026-06-04)
     * 'month' → DateFmt 'Y-m'     (e.g. 2026-06)
     * 'week'  → bucketed in PHP by ISO-week start (DateFmt has no week token,
     *           so we group daily rows by startOfWeek — stays DB-agnostic).
     *
     * @return array<int, array{bucket: string, revenue: float}>
     */
    public function revenueSeries(Carbon $from, Carbon $to, string $granularity = 'day'): array
    {
        if ($granularity === 'week') {
            return $this->weeklyFromDaily(
                $this->revenueSeries($from, $to, 'day'),
                valueKey: 'revenue',
            );
        }

        $bucket = $this->dateBucket('paid_at', $granularity === 'month' ? 'Y-m' : 'Y-m-d');

        return Invoice::query()
            ->where('status', InvoiceStatus::Paid)
            ->whereBetween('paid_at', [$from, $to])
            ->selectRaw("{$bucket} as bucket")
            ->selectRaw('SUM(amount) as revenue')
            ->groupBy('bucket')
            ->orderBy('bucket')
            ->get()
            ->map(fn ($row) => [
                'bucket'  => (string) $row->bucket,
                'revenue' => round((float) $row->revenue, 2),
            ])
            ->all();
    }

    /**
     * New-subscription counts across [from, to], bucketed by $granularity.
     *
     * @return array<int, array{bucket: string, count: int}>
     */
    public function signupSeries(Carbon $from, Carbon $to, string $granularity = 'day'): array
    {
        if ($granularity === 'week') {
            return $this->weeklyFromDaily(
                $this->signupSeries($from, $to, 'day'),
                valueKey: 'count',
            );
        }

        $bucket = $this->dateBucket('created_at', $granularity === 'month' ? 'Y-m' : 'Y-m-d');

        return Subscription::query()
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw("{$bucket} as bucket")
            ->selectRaw('COUNT(*) as count')
            ->groupBy('bucket')
            ->orderBy('bucket')
            ->get()
            ->map(fn ($row) => [
                'bucket' => (string) $row->bucket,
                'count'  => (int) $row->count,
            ])
            ->all();
    }

    // ─── Due / overdue invoice filters ───────────────────────────────────────

    /**
     * Accounts-receivable buckets keyed off invoice due_date — the heart of a
     * "money at risk" panel.
     *
     * @return array{overdue: int, due_today: int, due_this_week: int, outstanding_amount: float}
     */
    public function dueBreakdown(): array
    {
        $now = now();

        $pending = Invoice::query()->where('status', InvoiceStatus::Pending);

        return [
            // past due_date and still unpaid → dunning territory
            'overdue' => (clone $pending)
                ->whereNotNull('due_date')
                ->where('due_date', '<', $now)
                ->count(),

            // falls due before midnight tonight
            'due_today' => (clone $pending)
                ->whereBetween('due_date', [$now, $now->copy()->endOfDay()])
                ->count(),

            // falls due in the next 7 days
            'due_this_week' => (clone $pending)
                ->whereBetween('due_date', [$now, $now->copy()->addWeek()])
                ->count(),

            // total unpaid money on the books
            'outstanding_amount' => round((float) (clone $pending)->sum('amount'), 2),
        ];
    }

    /**
     * The actual overdue invoices (for an action list / collections queue).
     *
     * @return Collection<int, Invoice>
     */
    public function overdueInvoices(): Collection
    {
        return Invoice::query()
            ->where('status', InvoiceStatus::Pending)
            ->whereNotNull('due_date')
            ->where('due_date', '<', now())
            ->with('subscription.subscriber')
            ->orderBy('due_date')
            ->get();
    }

    // ─── Internals ───────────────────────────────────────────────────────────

    /**
     * Build a cross-database date-bucket SQL fragment using the package's
     * DateFmt expression. Resolving it against the active connection's grammar
     * yields the right dialect (MySQL date_format / Postgres to_char / SQLite
     * strftime / SQL Server format) — you write one query for all four.
     */
    private function dateBucket(string $column, string $format): string
    {
        $grammar = DB::connection(config('tashil.database.connection'))->getQueryGrammar();

        return (new DateFmt($column, $format))->getValue($grammar);
    }

    /**
     * Roll a daily series up into weekly buckets keyed by the Monday that
     * starts each ISO week. Kept in PHP so it works on every database.
     *
     * @param  array<int, array<string, mixed>>  $daily
     * @return array<int, array<string, mixed>>
     */
    private function weeklyFromDaily(array $daily, string $valueKey): array
    {
        $weeks = [];

        foreach ($daily as $row) {
            $weekStart = Carbon::parse($row['bucket'])->startOfWeek()->toDateString();
            $weeks[$weekStart] ??= 0;
            $weeks[$weekStart] += $row[$valueKey];
        }

        ksort($weeks);

        return array_map(
            fn ($bucket, $value) => ['bucket' => $bucket, $valueKey => $value],
            array_keys($weeks),
            array_values($weeks),
        );
    }
}
