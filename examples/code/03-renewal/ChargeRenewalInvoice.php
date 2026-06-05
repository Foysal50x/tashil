<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Services\PaymentGateway;
use Foysal50x\Tashil\Enums\TransactionStatus;
use Foysal50x\Tashil\Events\InvoiceIssued;
use Foysal50x\Tashil\Events\SubscriptionRenewed;
use Foysal50x\Tashil\Models\Invoice;
use Foysal50x\Tashil\Models\Transaction;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * RENEWAL FLOW — automatic recurring billing.
 *
 * The clock side is a cron; the money side is this listener:
 *
 *   tashil:renew-subscriptions (daily)
 *      └─ for each sub whose current_period_end has elapsed and auto_renew is on,
 *         issues a `renewal` invoice (status = Pending) → InvoiceIssued event
 *             │                                                          │
 *             │  IMPORTANT: the cron does NOT charge and does NOT        │  ◀── this listener
 *             │  advance the period. Issuing the invoice is all it does. │
 *             ▼                                                          ▼
 *   charge the saved card → record Transaction → invoice.markAsPaid()
 *      └─ InvoiceObserver sees Paid + kind=Renewal + sub Active/OnTrial
 *         → SubscriptionService::advancePeriod()  (period moves one cycle forward)
 *         → SubscriptionRenewed event
 *
 * So: the period advances ONLY when a renewal invoice is paid — never from the
 * cron alone. If the charge fails, the invoice stays Pending and overdue, and
 * tashil:process-dunning takes over (see 04-suspend).
 */
class ChargeRenewalInvoice implements ShouldQueue
{
    public function __construct(private readonly PaymentGateway $gateway) {}

    public function handle(InvoiceIssued $event): void
    {
        $invoice = $event->invoice;

        // InvoiceIssued fires for EVERY new invoice (initial, renewal,
        // proration). Auto-charge only renewals here — initial invoices are
        // paid interactively at checkout, proration deltas per your policy.
        if (! $invoice->isRenewal()) {
            return;
        }

        $subscription = $invoice->subscription;
        $subscriber = $subscription->subscriber;

        // Charge whatever payment method the subscriber has on file. Tashil
        // never stores cards — that's your gateway's vault.
        $charge = $this->gateway->chargeStoredMethod(
            subscriber: $subscriber,
            amount: (float) $invoice->amount,
            currency: $invoice->currency,
            idempotencyKey: "renewal-invoice-{$invoice->id}",   // dedupe retries
        );

        if (! $charge->successful()) {
            // Leave the invoice Pending. It becomes overdue at due_date and
            // dunning escalates it. Don't markPastDue here — the cron owns that.
            Log::warning('Renewal charge failed; dunning will pick it up', [
                'invoice_id'      => $invoice->id,
                'subscription_id' => $subscription->id,
                'reason'          => $charge->failureReason(),
            ]);

            return;
        }

        $this->settle($invoice, $charge->transactionId(), (float) $invoice->amount);
    }

    /**
     * Record the transaction and mark the renewal invoice paid in one tx.
     * markAsPaid() is the trigger that advances the billing period.
     */
    private function settle(Invoice $invoice, string $gatewayTxnId, float $amount): void
    {
        DB::transaction(function () use ($invoice, $gatewayTxnId, $amount) {
            Transaction::create([
                'invoice_id'     => $invoice->id,
                'gateway'        => 'stripe',
                'transaction_id' => $gatewayTxnId,
                'status'         => TransactionStatus::Success,
                'amount'         => $amount,
                'metadata'       => ['source' => 'auto-renewal'],
            ]);

            $invoice->markAsPaid();   // → advancePeriod() → SubscriptionRenewed
        });
    }
}

/**
 * Send the renewal receipt once the period has actually advanced.
 * Fires only after a renewal invoice is paid — not when the cron issues it.
 */
class SendRenewalReceipt implements ShouldQueue
{
    public function handle(SubscriptionRenewed $event): void
    {
        $subscription = $event->subscription;

        Log::info('Subscription renewed', [
            'subscription_id' => $subscription->id,
            'invoice_id'      => $event->invoice->id,
            'next_renewal'    => $subscription->current_period_end,
        ]);

        // $subscription->subscriber->notify(new RenewalReceiptNotification($event->invoice));
    }
}
