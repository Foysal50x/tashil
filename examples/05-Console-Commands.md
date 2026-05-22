# Console Commands

Tahsil ships six idempotent console commands. Auto-registered to the Laravel scheduler when `tashil.schedule.enabled` is true; override per-command via `tashil.schedule.overrides`.

## Defaults

| Command | Default cron | Purpose |
|---|---|---|
| `tashil:renew-subscriptions` | `5 0 * * *` (daily 00:05) | Issue a renewal invoice when `current_period_end` has elapsed for auto-renewing subs. |
| `tashil:expire-subscriptions` | `*/15 * * * *` | Promote subs past their access window (or past the grace cutoff) to `Expired`. |
| `tashil:expire-trials` | `*/30 * * * *` | Mark trials whose `trial_ends_at` has passed without conversion as `Expired` and dispatch `TrialExpired`. |
| `tashil:mark-trials-ending` | `55 7 * * *` (daily 07:55) | Dispatch `TrialEnding` for trials approaching expiry (window from `tashil.trial.warn_days`). |
| `tashil:reset-quotas` | `0 0 * * *` (daily 00:00) | Reset feature usage counters whose `period_end` has elapsed; period anchored to previous `period_end` to avoid cron drift. |
| `tashil:apply-pending-changes` | `*/5 * * * *` | Apply scheduled package changes (`scheduleDowngrade`) when their effective time arrives. |

All accept `--date=YYYY-MM-DD HH:MM:SS` to run as-of a specific moment (testing, backfill).

## Configuration

```php
// config/tashil.php
'schedule' => [
    'enabled'   => env('TASHIL_SCHEDULE_ENABLED', true),
    'overrides' => [
        'tashil:expire-trials' => '*/15 * * * *',
    ],
],

'renewal' => [
    // 'cancel' (default — preserves the historic tahsil behavior),
    // 'skip', or 'extend_grace'.
    'on_pending_invoice' => env('TASHIL_RENEWAL_ON_PENDING_INVOICE', 'cancel'),
    'grace_days'         => env('TASHIL_RENEWAL_GRACE_DAYS', 3),
],

'trial' => [
    'warn_days' => env('TASHIL_TRIAL_WARN_DAYS', 3),
],
```

## How renewal advances the period

`tashil:renew-subscriptions` only creates the invoice. `current_period_end` is advanced **when the invoice is marked paid** — handled by `InvoiceObserver`, which then dispatches `SubscriptionRenewed`. This keeps tahsil out of payment execution while still owning subscription state.

## Disabling auto-scheduling

Set `tashil.schedule.enabled` to `false` and wire commands manually in your host app's `Kernel`:

```php
$schedule->command('tashil:renew-subscriptions')->dailyAt('00:05')->onOneServer();
```
