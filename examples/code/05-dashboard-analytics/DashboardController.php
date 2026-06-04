<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Reporting\ReportingService;
use Foysal50x\Tashil\Facades\Tashil;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * ADMIN DASHBOARD — one endpoint, many date filters.
 *
 * GET /admin/dashboard?range=this_month
 * GET /admin/dashboard?range=7d&group=day
 * GET /admin/dashboard?range=this_year&group=month
 * GET /admin/dashboard?range=custom&from=2026-01-01&to=2026-03-31&group=week
 *
 * `range`  picks the [from, to] window (today, yesterday, 7d, 30d, this_week,
 *          this_month, this_quarter, this_year, custom).
 * `group`  picks the time-series granularity (day / week / month). When
 *          omitted it is auto-selected from the window length.
 *
 * The response combines:
 *   - all-time KPIs   (Tashil::analytics()->dashboardSummary())
 *   - windowed totals (revenue / signups / cancellations / conversions in range)
 *   - a grouped time series for charts
 *   - the accounts-receivable "due" breakdown
 */
class DashboardController extends Controller
{
    public function __construct(private readonly ReportingService $reports) {}

    public function __invoke(Request $request): JsonResponse
    {
        [$from, $to] = $this->resolveRange(
            preset: $request->query('range', 'this_month'),
            from: $request->query('from'),
            to: $request->query('to'),
        );

        // Granularity: explicit ?group=, else auto from the window length.
        $group = $request->query('group') ?? $this->autoGranularity($from, $to);

        return response()->json([
            'range' => [
                'preset' => $request->query('range', 'this_month'),
                'from'   => $from->toDateTimeString(),
                'to'     => $to->toDateTimeString(),
                'group'  => $group,
            ],

            // ── All-time KPI cards (package built-ins) ──
            'kpis' => $this->reports->kpis(),

            // ── Totals scoped to the selected window ──
            'window' => [
                'revenue'       => $this->reports->revenueBetween($from, $to),
                'new_subs'      => $this->reports->newSubscriptionsBetween($from, $to),
                'cancellations' => $this->reports->cancellationsBetween($from, $to),
                'conversions'   => $this->reports->conversionsBetween($from, $to),
            ],

            // ── Chart series, bucketed by day / week / month ──
            'series' => [
                'revenue' => $this->reports->revenueSeries($from, $to, $group),
                'signups' => $this->reports->signupSeries($from, $to, $group),
            ],

            // ── Accounts receivable: what's due / overdue right now ──
            'receivables' => $this->reports->dueBreakdown(),

            // ── A couple of long-horizon trends straight from the package ──
            'trends' => [
                'mrr_growth_12m' => Tashil::analytics()->revenueByPeriod(12),
                'churn_12m'      => Tashil::analytics()->churnTrend(12),
            ],
        ]);
    }

    /**
     * GET /admin/dashboard/overdue — the collections work list.
     */
    public function overdue(): JsonResponse
    {
        $invoices = $this->reports->overdueInvoices()->map(fn ($invoice) => [
            'invoice_id'   => $invoice->id,
            'number'       => $invoice->invoice_number,
            'amount'       => $invoice->amount,
            'due_date'     => $invoice->due_date,
            'days_overdue' => (int) $invoice->due_date->diffInDays(now()),
            'subscriber'   => $invoice->subscription?->subscriber?->getSubscriberKey(),
        ]);

        return response()->json([
            'summary'  => $this->reports->dueBreakdown(),
            'invoices' => $invoices,
        ]);
    }

    /**
     * Map a preset (or custom from/to) to a concrete [from, to] window.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function resolveRange(string $preset, ?string $from, ?string $to): array
    {
        $now = now();

        return match ($preset) {
            'today'        => [$now->copy()->startOfDay(),        $now->copy()->endOfDay()],
            'yesterday'    => [$now->copy()->subDay()->startOfDay(), $now->copy()->subDay()->endOfDay()],
            '7d'           => [$now->copy()->subDays(7)->startOfDay(),  $now->copy()->endOfDay()],
            '30d'          => [$now->copy()->subDays(30)->startOfDay(), $now->copy()->endOfDay()],
            'this_week'    => [$now->copy()->startOfWeek(),       $now->copy()->endOfWeek()],
            'this_month'   => [$now->copy()->startOfMonth(),      $now->copy()->endOfMonth()],
            'this_quarter' => [$now->copy()->startOfQuarter(),    $now->copy()->endOfQuarter()],
            'this_year'    => [$now->copy()->startOfYear(),       $now->copy()->endOfYear()],
            'custom'       => [
                Carbon::parse($from ?? $now->copy()->startOfMonth())->startOfDay(),
                Carbon::parse($to ?? $now)->endOfDay(),
            ],
            default        => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()],
        };
    }

    /**
     * Sensible default granularity: day for ≤ 31-day windows, week for ≤ ~6
     * months, month beyond that. Keeps charts readable without a manual ?group.
     */
    private function autoGranularity(Carbon $from, Carbon $to): string
    {
        $days = $from->diffInDays($to);

        return match (true) {
            $days <= 31  => 'day',
            $days <= 186 => 'week',
            default      => 'month',
        };
    }
}
