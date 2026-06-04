<?php

declare(strict_types=1);

/**
 * REGISTERING THE DUNNING + EXPIRY COMMANDS
 * ==========================================
 *
 *   tashil:process-dunning       Walks every overdue, still-Pending invoice and
 *                                escalates its subscription:
 *                                Active → PastDue → Suspended → Expired,
 *                                firing SubscriptionPastDue / InvoiceOverdue /
 *                                SubscriptionSuspended / SubscriptionExpired so
 *                                the host can retry the charge and revoke access.
 *                                Each invoice is processed under a per-subscription
 *                                lock in its own transaction.
 *
 *   tashil:expire-subscriptions  Promotes subs past their access window (grace
 *                                cancel that ran out, or suspend grace elapsed)
 *                                to Expired.
 *
 * Tashil NEVER charges during dunning — it only owns the state machine and the
 * schedule. The retry charge lives in your RetryDunningCharge listener.
 *
 * Default cadence (auto-wired when tashil.schedule.enabled is true):
 *     tashil:process-dunning        every 30 minutes
 *     tashil:expire-subscriptions   every 15 minutes
 *
 * Run by hand / time-travel for testing the whole escalation:
 *     php artisan tashil:process-dunning
 *     php artisan tashil:process-dunning --date="2026-08-10 09:00:00"
 */

return [
    'schedule' => [
        'enabled' => env('TASHIL_SCHEDULE_ENABLED', true),
    ],

    'dunning' => [
        'enabled' => env('TASHIL_DUNNING_ENABLED', true),

        // Days after an invoice's due_date at which each retry milestone fires.
        // Three entries here = up to three PastDue retries before suspension.
        'retry_days' => [1, 3, 5],

        // Suspend (cut access) once dunning_attempts reaches this.
        'suspend_after_attempts' => env('TASHIL_DUNNING_SUSPEND_AFTER', 3),

        // Expire a still-unpaid suspended subscription this many days later.
        'cancel_after_suspend_days' => env('TASHIL_DUNNING_CANCEL_AFTER', 7),

        // Soft dunning: keep access during the PastDue retry window.
        // Suspended ALWAYS loses access regardless of this flag.
        'keep_access_while_past_due' => env('TASHIL_PASTDUE_KEEP_ACCESS', true),
    ],
];

// Example timeline with the config above, for a renewal invoice due 2026-08-01:
//
//   Aug 02  attempt 1 → PastDue   (SubscriptionPastDue, attempt=1) → retry charge
//   Aug 04  attempt 2 → PastDue   (attempt=2)                       → retry charge
//   Aug 06  attempt 3 → Suspended (SubscriptionSuspended)           → revoke access
//   Aug 13  +7 days   → Expired   (SubscriptionExpired)
//
// Paying the invoice on, say, Aug 05 short-circuits everything:
//   markAsPaid() → reactivate() → Active, dunning_attempts reset to 0.
//
// Manual scheduling (only if tashil.schedule.enabled = false), Laravel 11+:
//     use Illuminate\Support\Facades\Schedule;
//     Schedule::command('tashil:process-dunning')->everyThirtyMinutes()->onOneServer();
//     Schedule::command('tashil:expire-subscriptions')->everyFifteenMinutes()->onOneServer();
