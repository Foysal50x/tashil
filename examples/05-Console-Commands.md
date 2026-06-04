# Console Commands

Tahsil ships seven idempotent console commands. Auto-registered to the Laravel scheduler when `tashil.schedule.enabled` is true; override per-command via `tashil.schedule.overrides`.

## Defaults

| Command | Default cron | Purpose |
|---|---|---|
| `tashil:renew-subscriptions` | `5 0 * * *` (daily 00:05) | Issue a renewal invoice when `current_period_end` has elapsed for auto-renewing subs. |
| `tashil:process-dunning` | `*/30 * * * *` | Escalate unpaid overdue invoices: `Active → PastDue → Suspended → Expired` on the dunning schedule. |
| `tashil:expire-subscriptions` | `*/15 * * * *` | Promote subs past their access window (or past the grace cutoff) to `Expired`. |
| `tashil:expire-trials` | `*/30 * * * *` | Mark trials whose `trial_ends_at` has passed without conversion as `Expired` and dispatch `TrialExpired`. |
| `tashil:mark-trials-ending` | `55 7 * * *` (daily 07:55) | Dispatch `TrialEnding` for trials approaching expiry (window from `tashil.trial.warn_days`). |
| `tashil:reset-quotas` | `0 0 * * *` (daily 00:00) | Reset feature usage counters whose `period_end` has elapsed; period anchored to previous `period_end` to avoid cron drift. |
| `tashil:apply-pending-changes` | `*/5 * * * *` | Apply scheduled package changes (`scheduleDowngrade`) when their effective time arrives. |

All accept `--date=Y-m-d H:i:s` to run as-of a specific moment (testing, backfill).

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
    'on_pending_invoice'   => env('TASHIL_RENEWAL_ON_PENDING_INVOICE', 'cancel'),
    'grace_days'           => env('TASHIL_RENEWAL_GRACE_DAYS', 3),
    // cap on how many times 'extend_grace' may push the period before giving
    // up — stops an unpaid invoice from extending access forever.
    'max_grace_extensions' => env('TASHIL_RENEWAL_MAX_GRACE_EXTENSIONS', 3),
],

'dunning' => [
    'enabled'                    => env('TASHIL_DUNNING_ENABLED', true),
    // days after due_date at which each retry milestone fires
    'retry_days'                 => [1, 3, 5],
    // suspend (cut access) once the attempt count reaches this
    'suspend_after_attempts'     => env('TASHIL_DUNNING_SUSPEND_AFTER', 3),
    // expire a still-unpaid suspended subscription this many days after suspension
    'cancel_after_suspend_days'  => env('TASHIL_DUNNING_CANCEL_AFTER', 7),
    // soft dunning: keep access during the PastDue retry window (Suspended never has access)
    'keep_access_while_past_due' => env('TASHIL_PASTDUE_KEEP_ACCESS', true),
],

'trial' => [
    'warn_days' => env('TASHIL_TRIAL_WARN_DAYS', 3),
],
```

## How renewal advances the period

`tashil:renew-subscriptions` only creates the invoice. `current_period_end` is advanced **when the invoice is marked paid** — handled by `InvoiceObserver`, which then dispatches `SubscriptionRenewed`. This keeps tahsil out of payment execution while still owning subscription state.

## How dunning escalates an unpaid invoice

`tashil:process-dunning` walks every overdue, still-`Pending` invoice and advances its subscription through the recovery lifecycle, anchored to `due_date`:

```text
Active ──(retry_days milestone)──▶ PastDue ──(attempts ≥ suspend_after_attempts)──▶ Suspended ──(+ cancel_after_suspend_days)──▶ Expired
```

Each milestone increments the subscription's `dunning_attempts`, fires `SubscriptionPastDue` (and the existing `InvoiceOverdue`) so the host can re-attempt the charge, and — at suspension/expiry — `SubscriptionSuspended` / `SubscriptionExpired`. Tahsil never charges; it only owns state. Whether `PastDue` keeps access is `dunning.keep_access_while_past_due`; `Suspended` always loses it.

**Paying recovers automatically:** `markAsPaid()` on the overdue invoice (before expiry) reactivates the subscription and clears the dunning counters — see [03-Billing-and-Invoicing.md](./03-Billing-and-Invoicing.md). Each invoice is processed under a per-subscription lock and in its own transaction; the command's exit code is non-zero if any invoice failed (so a stuck one is surfaced to your cron monitor) while the rest still commit.

## Disabling auto-scheduling

Set `tashil.schedule.enabled` to `false` and wire commands manually in your host app's `Kernel`:

```php
$schedule->command('tashil:renew-subscriptions')->dailyAt('00:05')->onOneServer();
```
