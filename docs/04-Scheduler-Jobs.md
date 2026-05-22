# Scheduler Jobs

Tahsil ships six console commands. The service provider auto-registers them with Laravel's scheduler when `tashil.schedule.enabled` is true (the default). Cadence per command is overridable via `tashil.schedule.overrides`, or you can disable auto-registration and wire commands manually for the Laravel version you're on (see [Disabling auto-registration](#disabling-auto-registration) below — the wiring differs between Laravel 10 and Laravel 11+).

## Defaults

| Command | Default cron | Purpose | Driving column |
|---|---|---|---|
| `tashil:renew-subscriptions` | `5 0 * * *` (daily 00:05) | Issue renewal invoices for auto-renewing subs past period end. | `current_period_end` |
| `tashil:expire-subscriptions` | `*/15 * * * *` | Promote subs past their access window to `Expired`. | `ends_at`, `cancellation_effective_at` |
| `tashil:expire-trials` | `*/30 * * * *` | Mark trials whose `trial_ends_at` has passed without conversion as `Expired`. | `trial_ends_at`, `trial_converted_at` |
| `tashil:mark-trials-ending` | `55 7 * * *` (daily 07:55) | Dispatch `TrialEnding` for trials approaching expiry. | `trial_ends_at`, `tashil.trial.warn_days` |
| `tashil:reset-quotas` | `0 0 * * *` (daily 00:00) | Reset feature usage counters whose `period_end` has elapsed. | `period_end`, `reset_period` |
| `tashil:apply-pending-changes` | `*/5 * * * *` | Apply scheduled package changes (e.g. queued downgrades). | `pending_change_at` |

All commands accept a `--date=Y-m-d H:i:s` option to run as-of an arbitrary moment — handy for tests and one-off backfills.

All commands run with `->onOneServer()` so multi-server deploys do not double-process.

## Configuration

```php
// config/tashil.php
'schedule' => [
    'enabled'   => env('TASHIL_SCHEDULE_ENABLED', true),
    'overrides' => [
        // override per command
        'tashil:expire-trials' => '*/15 * * * *',
    ],
],

'trial' => [
    'warn_days' => env('TASHIL_TRIAL_WARN_DAYS', 3),
],

'renewal' => [
    'on_pending_invoice' => env('TASHIL_RENEWAL_ON_PENDING_INVOICE', 'cancel'),
    'grace_days'         => env('TASHIL_RENEWAL_GRACE_DAYS', 3),
],
```

Set `schedule.enabled = false` and wire commands in your `app/Console/Kernel.php` manually if you want full control over orchestration.

## Idempotency

Every command is safe to re-run. Concretely:

- `renew-subscriptions` checks for an existing pending invoice before issuing a new one. The policy (`cancel` / `skip` / `extend_grace`) decides what to do when one exists.
- `expire-subscriptions` is a no-op for rows already in `Expired`.
- `expire-trials` filters by `trial_converted_at IS NULL` — converted trials are never expired.
- `mark-trials-ending` appends an event with idempotency key `"trial-ending:{sub_id}:{date}"`, so a same-day re-run dispatches no duplicate event.
- `reset-quotas` advances `period_start`/`period_end` only when `period_end <= now()`. Anchor-to-previous-`period_end` means re-running after a delayed run doesn't accumulate skipped windows.
- `apply-pending-changes` clears `pending_package_id` / `pending_change_at` once applied; re-runs see no candidates.

## `tashil:renew-subscriptions` in detail

Selects auto-renewing subscriptions with elapsed `current_period_end`. For each, one of:

| Policy (`tashil.renewal.on_pending_invoice`) | Existing pending invoice present | Action |
|---|---|---|
| `cancel` (default) | yes | Grace-cancel the subscription (`reason = Auto-renewal failed: pending invoice exists`). |
| `skip` | yes | Log and move on; retry next run. |
| `extend_grace` | yes | Push `current_period_end` forward by `tashil.renewal.grace_days`. |
| any | no | Call `BillingService::generateInvoice()` and dispatch `InvoiceIssued`. |

Important: this command **does not advance** `current_period_end`. The advance happens when the host marks the invoice as `Paid`, via `InvoiceObserver::updated` → `SubscriptionService::advancePeriod`. That keeps tahsil out of payment execution.

## `tashil:expire-subscriptions` in detail

Promotes a subscription to `Expired` when either:

- `status ∈ {Active, OnTrial}`, `auto_renew = false`, `ends_at <= now()`; or
- `status = PendingCancellation`, `cancellation_effective_at <= now()`.

Each transition calls `SubscriptionService::expire()` which appends `subscription.expired` and dispatches `SubscriptionExpired`.

## `tashil:reset-quotas` in detail

For every `feature_usages` row where `reset_period != never` and `period_end <= now()`:

- `usage` set to 0.
- `period_start` set to the previous `period_end`.
- `period_end` advanced by one window (anchored, not relative to `now()`).
- A `tashil_usage_logs` row recorded with `operation = reset`, `previous_usage`, `new_usage = 0`.
- Event appended: `usage.reset` with idempotency key `"usage-reset:{usage_id}:YYYY-MM-DD-HH"`.
- `UsageReset` dispatched.

The anchor rule guarantees that a one-hour-late cron does not push the next reset one hour later.

## `tashil:apply-pending-changes` in detail

Picks rows where both `pending_package_id` and `pending_change_at` are set and `pending_change_at <= now()`. For each, calls `SubscriptionService::applyPendingChange()` which internally invokes `switchPlan()`, then dispatches `PendingChangeApplied` carrying the old and new subscription. The pending fields are cleared by `switchPlan()`'s side effect (the old subscription is now Cancelled; the new one is the active row).

## Locking & ordering

All commands rely on the `EventStore::append` `SELECT … FOR UPDATE` on `tashil_subscriptions.id` to serialize state transitions per subscription. Two jobs processing the same subscription in the same moment will serialize behind that lock; per-row `sequence_num` remains strictly monotonic.

Across different subscriptions, jobs run in parallel without contention — the lock is per row.

## Disabling auto-registration

Set the config flag, then wire what you want yourself:

```php
// config/tashil.php
'schedule' => ['enabled' => false],
```

The wiring API differs by Laravel major version. Tahsil works on all of them; only the host-app entry point changes.

### Laravel 10 — `app/Console/Kernel.php`

```php
// app/Console/Kernel.php
use Illuminate\Console\Scheduling\Schedule;

protected function schedule(Schedule $schedule): void
{
    $schedule->command('tashil:renew-subscriptions')->dailyAt('00:05')->onOneServer();
    $schedule->command('tashil:expire-subscriptions')->everyFifteenMinutes()->onOneServer();
    $schedule->command('tashil:expire-trials')->everyThirtyMinutes()->onOneServer();
    $schedule->command('tashil:reset-quotas')->dailyAt('00:00')->onOneServer();
    $schedule->command('tashil:apply-pending-changes')->everyFiveMinutes()->onOneServer();
    $schedule->command('tashil:mark-trials-ending')->dailyAt('07:55')->onOneServer();
}
```

### Laravel 11 / 12 / 13 — `routes/console.php` (recommended)

`app/Console/Kernel.php` was removed in Laravel 11. The idiomatic place is `routes/console.php`:

```php
// routes/console.php
use Illuminate\Support\Facades\Schedule;

Schedule::command('tashil:renew-subscriptions')->dailyAt('00:05')->onOneServer();
Schedule::command('tashil:expire-subscriptions')->everyFifteenMinutes()->onOneServer();
Schedule::command('tashil:expire-trials')->everyThirtyMinutes()->onOneServer();
Schedule::command('tashil:reset-quotas')->dailyAt('00:00')->onOneServer();
Schedule::command('tashil:apply-pending-changes')->everyFiveMinutes()->onOneServer();
Schedule::command('tashil:mark-trials-ending')->dailyAt('07:55')->onOneServer();
```

### Laravel 11 / 12 / 13 — `bootstrap/app.php` (alternative)

Use this when you prefer to keep `routes/console.php` reserved for command *definitions* only:

```php
// bootstrap/app.php
use Illuminate\Console\Scheduling\Schedule;

return Application::configure(basePath: dirname(__DIR__))
    // … other configuration …
    ->withSchedule(function (Schedule $schedule) {
        $schedule->command('tashil:renew-subscriptions')->dailyAt('00:05')->onOneServer();
        $schedule->command('tashil:expire-subscriptions')->everyFifteenMinutes()->onOneServer();
        $schedule->command('tashil:expire-trials')->everyThirtyMinutes()->onOneServer();
        $schedule->command('tashil:reset-quotas')->dailyAt('00:00')->onOneServer();
        $schedule->command('tashil:apply-pending-changes')->everyFiveMinutes()->onOneServer();
        $schedule->command('tashil:mark-trials-ending')->dailyAt('07:55')->onOneServer();
    })
    ->create();
```

Both forms produce identical behavior — Laravel resolves the same `Illuminate\Console\Scheduling\Schedule` singleton either way.

### Note: tahsil's own auto-wiring is version-agnostic

`TashilServiceProvider::registerSchedule()` resolves the `Schedule` instance via `$this->app->booted(...)` and calls `$schedule->command(...)`. That binding has existed since Laravel 5, so the same provider code wires correctly under L10's Kernel-based scheduling and L11+'s `bootstrap/app.php` flow — no per-version branching needed inside the package.

## Verifying

```bash
php artisan schedule:list
```

You should see all six tahsil commands when `tashil.schedule.enabled = true`, regardless of Laravel version.
