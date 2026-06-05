<?php

declare(strict_types=1);

/**
 * REGISTERING THE TRIAL CONSOLE COMMANDS
 * =======================================
 *
 * Two scheduled commands drive the *unattended* side of the trial lifecycle —
 * the parts that happen with no user in the request:
 *
 *   tashil:mark-trials-ending   Fires TrialEnding for trials within
 *                               tashil.trial.warn_days of expiry. Drives your
 *                               "your trial ends in 3 days" reminder. Appends a
 *                               trial.ending event with an idempotency key so a
 *                               re-run on the same day never double-sends.
 *
 *   tashil:expire-trials        Flips OnTrial → Expired for trials whose
 *                               trial_ends_at has passed without conversion,
 *                               and dispatches TrialExpired. This is what
 *                               actually REVOKES access from a dead trial.
 *
 * Both are idempotent and both accept --date="Y-m-d H:i:s" to run as-of a
 * moment (handy for backfills and tests).
 *
 * You have two ways to register them. Pick ONE.
 */

// ─────────────────────────────────────────────────────────────────────────────
// OPTION A — Auto-scheduling (default, recommended)
// ─────────────────────────────────────────────────────────────────────────────
//
// When `tashil.schedule.enabled` is true (the default), Tashil wires ALL its
// commands into Laravel's scheduler for you across every Laravel version — you
// don't touch the kernel at all. Just make sure a system cron runs the Laravel
// scheduler every minute:
//
//     * * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
//
// Default cadence (overridable):
//     tashil:mark-trials-ending   55 7 * * *     (daily 07:55)
//     tashil:expire-trials        */30 * * * *   (every 30 min)
//
// Tune cadence or the warning window in config/tashil.php:

return [
    'schedule' => [
        'enabled'   => env('TASHIL_SCHEDULE_ENABLED', true),
        'overrides' => [
            // run the expiry sweep more often so dead trials lose access faster
            'tashil:expire-trials' => '*/15 * * * *',
        ],
    ],

    'trial' => [
        // dispatch TrialEnding this many days before trial_ends_at
        'warn_days' => env('TASHIL_TRIAL_WARN_DAYS', 3),
    ],
];

// ─────────────────────────────────────────────────────────────────────────────
// OPTION B — Manual scheduling (set tashil.schedule.enabled = false first)
// ─────────────────────────────────────────────────────────────────────────────
//
// Laravel 11+  →  routes/console.php
//
//     use Illuminate\Support\Facades\Schedule;
//
//     Schedule::command('tashil:mark-trials-ending')->dailyAt('07:55')->onOneServer();
//     Schedule::command('tashil:expire-trials')->everyThirtyMinutes()->onOneServer();
//
// Laravel 10   →  app/Console/Kernel.php  schedule()
//
//     protected function schedule(Schedule $schedule): void
//     {
//         $schedule->command('tashil:mark-trials-ending')->dailyAt('07:55')->onOneServer();
//         $schedule->command('tashil:expire-trials')->everyThirtyMinutes()->onOneServer();
//     }
//
// `onOneServer()` stops two app servers from both running the sweep; the
// commands are idempotent anyway, but it avoids duplicate work.
//
// Run them by hand to test:
//
//     php artisan tashil:mark-trials-ending
//     php artisan tashil:expire-trials --date="2026-07-01 09:00:00"
