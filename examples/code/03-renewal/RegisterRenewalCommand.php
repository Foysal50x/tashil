<?php

declare(strict_types=1);

/**
 * REGISTERING THE RENEWAL COMMAND
 * ================================
 *
 *   tashil:renew-subscriptions   Issues a `renewal` invoice for every
 *                                auto-renewing subscription whose
 *                                current_period_end has elapsed.
 *
 * What it does NOT do (by design — Tashil never touches money):
 *   - it does NOT charge a card,
 *   - it does NOT advance current_period_end.
 *
 * The period advances only when the issued invoice is marked paid (the
 * ChargeRenewalInvoice listener charges and calls markAsPaid; InvoiceObserver
 * then routes the paid renewal to advancePeriod()). If a test expects the
 * period to move from running the command alone, the test is wrong.
 *
 * Default cadence:  5 0 * * *   (daily 00:05). Auto-wired when
 * tashil.schedule.enabled is true — nothing to register. Run by hand:
 *
 *     php artisan tashil:renew-subscriptions
 *     php artisan tashil:renew-subscriptions --date="2026-08-01 00:05:00"
 *
 * The command's exit code is non-zero if any single renewal throws, so a stuck
 * subscription surfaces to your cron monitor while the rest still commit.
 */

// ─────────────────────────────────────────────────────────────────────────────
// What happens when a PRIOR invoice is still unpaid at renewal time?
// ─────────────────────────────────────────────────────────────────────────────
//
// Controlled by tashil.renewal.on_pending_invoice:
//
//   'cancel'        (default) — don't stack a second invoice; let dunning run
//                               its course on the existing unpaid one.
//   'skip'          — skip this subscriber this run, retry next run.
//   'extend_grace'  — push current_period_end out by renewal.grace_days, up to
//                     renewal.max_grace_extensions times, then give up.

return [
    'schedule' => [
        'enabled'   => env('TASHIL_SCHEDULE_ENABLED', true),
        'overrides' => [
            // e.g. issue renewals twice a day
            // 'tashil:renew-subscriptions' => '5 0,12 * * *',
        ],
    ],

    'renewal' => [
        'on_pending_invoice'   => env('TASHIL_RENEWAL_ON_PENDING_INVOICE', 'cancel'),
        'grace_days'           => env('TASHIL_RENEWAL_GRACE_DAYS', 3),
        'max_grace_extensions' => env('TASHIL_RENEWAL_MAX_GRACE_EXTENSIONS', 3),
    ],
];

// ─────────────────────────────────────────────────────────────────────────────
// Manual scheduling (only if tashil.schedule.enabled = false)
// ─────────────────────────────────────────────────────────────────────────────
//
// Laravel 11+  routes/console.php:
//     use Illuminate\Support\Facades\Schedule;
//     Schedule::command('tashil:renew-subscriptions')->dailyAt('00:05')->onOneServer();
//
// Laravel 10   app/Console/Kernel.php:
//     $schedule->command('tashil:renew-subscriptions')->dailyAt('00:05')->onOneServer();
