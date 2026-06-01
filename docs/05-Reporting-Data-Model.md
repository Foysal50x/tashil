# Reporting Data Model

Tahsil's reporting story has two layers:

1. **`AnalyticsService`** — current-state metrics computed with `tpetry/laravel-query-expressions`. Cross-database, batched, no raw SQL. Used by host dashboards.
2. **Event store + immutable snapshots + usage logs** — append-only history that supports point-in-time queries, replay, and audit. Used when "what was the state on date X?" matters more than aggregate KPIs.

Billing-tier revenue reporting (MRR waterfall, NDR, cohort LTV) is intentionally out of scope — that belongs in the host or in a downstream warehouse where invoice-paid data from the gateway is the source of truth.

## Layer 1 — `AnalyticsService` (live aggregates)

Entry point: `Tashil::analytics()`. All methods read from `tashil_subscriptions`, `tashil_packages`, `tashil_invoices` directly. No materialized tables.

### Subscription metrics

| Method | Returns | Notes |
|---|---|---|
| `totalSubscriptionCount()` | `int` | All subs across all statuses. |
| `activeSubscriptionCount()` | `int` | `Active + OnTrial`. |
| `subscriptionCountByStatus()` | `array<string,int>` | One row per status. |
| `subscribersByPackage()` | `array` | Active+trial count grouped by package. |
| `trialConversionRate()` | `float` | % of trials that reached Active. |
| `subscriptionGrowth(int $months = 12)` | `array` | Monthly new-subscription count. |

### Revenue / invoice metrics

| Method | Returns | Notes |
|---|---|---|
| `calculateMRR()` | `float` | All active+trial subs, normalized to monthly via package billing period. |
| `averageRevenuePerUser()` | `float` | MRR ÷ active count. |
| `totalRevenue()` | `float` | Sum of paid invoice amounts. |
| `revenueByPeriod(int $months = 12)` | `array` | Monthly revenue trend from paid invoices. |
| `revenueByPackage()` | `array` | Revenue grouped by package. |
| `pendingInvoiceCount()` / `overdueInvoiceCount()` | `int` | |

### Churn

| Method | Returns | Notes |
|---|---|---|
| `churnRate(int $days = 30)` | `float` | Cancelled-in-window / total. |
| `churnTrend(int $months = 12, int $windowDays = 30)` | `array` | Batched — 2 queries total, computed in PHP. |

### One-shot dashboard

`dashboardSummary()` and `packageAnalytics()` consolidate the above into one or two batched queries using `CaseGroup` / `CountFilter` / `SumFilter`. Use these in admin views to avoid N+1.

### Caching

`AnalyticsService` resolves the repos through the cache decorator (`CacheSubscriptionRepository`, `CacheInvoiceRepository`). Aggregates are cached at the repo level with the default TTL. Writes that affect aggregates invalidate the relevant keys. Disable with `tashil.cache.enabled = false`.

## Layer 2 — Audit, replay, point-in-time

The event log + snapshot tables are the source of truth for "what happened?" questions.

### The event log (`tashil_subscription_events`)

Append-only, monotonic per subscription. Every state transition produced by `SubscriptionService` and friends writes one row. Common event types:

```
subscription.created
subscription.cancelled        (payload: immediate, reason)
subscription.resumed
subscription.expired
subscription.switched         (payload: new_subscription_id, new_package_id, new_package_slug)
subscription.paused / .unpaused
subscription.renewed          (payload: new_period_end)
subscription.pending_change_scheduled / .pending_change_cancelled
trial.ending                  (payload: days_remaining)
trial.converted
trial.expired
usage.reset                   (payload: feature_id, previous_usage)
usage.metered_charged         (payload: feature_id, units, unit_price, amount, currency)
```

Reading the log:

```php
$sub->events()->get();                 // ordered by sequence_num
$sub->events()->ofType('trial.expired')->get();
$sub->events()->upTo(now()->subWeek())->get();
```

Or via the repository contract for batch / cross-subscription work:

```php
app(\Foysal50x\Tashil\Contracts\SubscriptionEventRepositoryInterface::class)
    ->listForSubscription($sub, 'subscription.switched', 100);
```

### The feature snapshot (`tashil_subscription_features`)

One row per (subscription, feature, lifetime-segment). `superseded_at` marks the moment a snapshot row was replaced — current rows have `null`, historical rows have the supersession timestamp.

```php
// What features did this subscription have on a given day?
app(\Foysal50x\Tashil\Contracts\SubscriptionFeatureRepositoryInterface::class)
    ->asOf($sub, now()->subDays(30));
```

This is the underlying mechanism for "what plan did user X have on date Y?" — the snapshot rows capture `feature_slug`/`value`/`reset_period` at that moment, and the event store records the plan_id transition.

### The usage log (`tashil_usage_logs`)

Every increment / reset / report / adjust is captured with `previous_usage` and `new_usage`. The host can:

- Reconstruct any counter at any moment by summing forward from period start.
- Build daily/weekly usage histograms (`HasSubscriptions::dailyUsageFor()` already does this).
- Drive a usage-based billing system without modifying tahsil.

## Composing a point-in-time picture

To answer "what plan, with what features, with what usage did subscription S have at moment T?":

1. **Plan** — walk `subscription_events` for S ordered by `sequence_num`, take the last `subscription.created` / `subscription.switched` with `occurred_at ≤ T`. The payload carries the package id.
2. **Features** — `SubscriptionFeatureRepository::asOf($sub, $T)`.
3. **Usage** — sum `usage_logs.new_usage`-deltas where `created_at ≤ T` for each `(subscription_id, feature_id)`, or replay from `previous_usage` of the first log row in the period.

## What tahsil does *not* track in reporting

- Card-level revenue, chargebacks, refunds executed by the gateway.
- MRR movement waterfall (new / expansion / contraction / churn / reactivation) — straightforward to build from the event log in a downstream tool, but not provided here.
- Cohort retention curves.
- Multi-currency normalization with an FX rate table.
- Coupon redemption attribution.

If you need any of these, the event log + invoice rows are sufficient inputs for a host-side reporting job.
