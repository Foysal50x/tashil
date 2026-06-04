<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Services\PaymentGateway;
use Foysal50x\Tashil\Enums\TransactionStatus;
use Foysal50x\Tashil\Events\SubscriptionPastDue;
use Foysal50x\Tashil\Events\SubscriptionReactivated;
use Foysal50x\Tashil\Events\SubscriptionSuspended;
use Foysal50x\Tashil\Models\Invoice;
use Foysal50x\Tashil\Models\Transaction;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * DUNNING / SUSPENSION — the recovery state machine for an unpaid renewal.
 *
 * Tashil owns the STATE MACHINE and SCHEDULE (the tashil:process-dunning
 * command); the host owns the CHARGE. The command walks every overdue, still
 * Pending invoice and escalates, anchored to the invoice due_date and the
 * tashil.dunning.* config:
 *
 *   Active ──(retry milestone)──▶ PastDue ──(attempts exhausted)──▶ Suspended ──(grace)──▶ Expired
 *      │                              │                                  │
 *      │                  SubscriptionPastDue + InvoiceOverdue           │  SubscriptionSuspended
 *      │                  (RETRY THE CHARGE here)  ◀── RetryDunningCharge │  (CUT ACCESS here) ◀── RevokeAccessOnSuspend
 *      ▼                              ▼                                  ▼
 *   ...paying the overdue invoice at ANY point before Expired calls
 *      InvoiceObserver → SubscriptionService::reactivate() automatically →
 *      Active again, dunning counters cleared → SubscriptionReactivated.
 *
 * PastDue keeps access while tashil.dunning.keep_access_while_past_due is true
 * (soft dunning). Suspended NEVER has access.
 */

/**
 * Each PastDue milestone is the host's cue to re-attempt the charge. Tashil has
 * already advanced the state and fired the event; we only move money. On a
 * successful retry we markAsPaid() and Tashil reactivates the subscription.
 */
class RetryDunningCharge implements ShouldQueue
{
    public function __construct(private readonly PaymentGateway $gateway) {}

    public function handle(SubscriptionPastDue $event): void
    {
        $invoice      = $event->invoice;      // the overdue renewal invoice
        $subscription = $event->subscription;
        $attempt      = $event->attempt;      // which retry milestone this is

        Log::info('Dunning retry', [
            'subscription_id' => $subscription->id,
            'invoice_id'      => $invoice->id,
            'attempt'         => $attempt,
        ]);

        $charge = $this->gateway->chargeStoredMethod(
            subscriber: $subscription->subscriber,
            amount: (float) $invoice->amount,
            currency: $invoice->currency,
            idempotencyKey: "dunning-{$invoice->id}-attempt-{$attempt}",
        );

        if (! $charge->successful()) {
            // Still failing — let the next scheduled milestone try again, or let
            // it escalate to Suspended. Do NOT change state here; the cron owns it.
            return;
        }

        // Recovered. markAsPaid() routes through InvoiceObserver → reactivate().
        DB::transaction(function () use ($invoice, $charge) {
            Transaction::create([
                'invoice_id'     => $invoice->id,
                'gateway'        => 'stripe',
                'transaction_id' => $charge->transactionId(),
                'status'         => TransactionStatus::Success,
                'amount'         => (float) $invoice->amount,
                'metadata'       => ['source' => 'dunning-retry'],
            ]);

            $invoice->markAsPaid();
        });
    }
}

/**
 * Retries are exhausted and access is now cut. Revoke everything the customer
 * shouldn't keep while suspended. Suspended is a hard "no access" state, so
 * this runs once on the transition — not on every PastDue retry.
 */
class RevokeAccessOnSuspend implements ShouldQueue
{
    public function handle(SubscriptionSuspended $event): void
    {
        $subscription = $event->subscription;
        $subscriber   = $subscription->subscriber;

        Log::warning('Subscription suspended — revoking access', [
            'subscription_id' => $subscription->id,
            'suspended_at'    => $subscription->suspended_at,
        ]);

        // Host-specific teardown:
        // $subscriber->apiKeys()->update(['revoked_at' => now()]);
        // $subscriber->sessions()->delete();
        // $subscriber->notify(new AccountSuspendedNotification());
    }
}

/**
 * The happy ending: a lapsed subscription recovered (PastDue/Suspended/Expired
 * → Active) because the overdue invoice got paid. Restore whatever
 * RevokeAccessOnSuspend tore down.
 */
class RestoreAccessOnReactivation implements ShouldQueue
{
    public function handle(SubscriptionReactivated $event): void
    {
        $subscription = $event->subscription;

        Log::info('Subscription reactivated — restoring access', [
            'subscription_id' => $subscription->id,
            'paid_invoice'    => $event->invoice?->id,
            'period_end'      => $subscription->current_period_end,
        ]);

        // $subscription->subscriber->apiKeys()->update(['revoked_at' => null]);
    }
}
