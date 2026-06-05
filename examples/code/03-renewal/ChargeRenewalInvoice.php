<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Services\PaymentGateway;
use Foysal50x\Tashil\Events\InvoiceIssued;
use Foysal50x\Tashil\Events\SubscriptionRenewed;
use Foysal50x\Tashil\Facades\Tashil;
use Foysal50x\Tashil\Models\Invoice;
use Illuminate\Contracts\Queue\ShouldQueue;
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
            // Record the failed attempt for the audit trail (optional but handy
            // for support); it does NOT change the invoice state.
            Tashil::billing()->recordFailedPayment(
                invoice: $invoice,
                gateway: 'stripe',
                gatewayResponse: ['reason' => $charge->failureReason()],
                metadata: ['source' => 'auto-renewal'],
            );

            Log::warning('Renewal charge failed; dunning will pick it up', [
                'invoice_id'      => $invoice->id,
                'subscription_id' => $subscription->id,
                'reason'          => $charge->failureReason(),
            ]);

            return;
        }

        // One call records the transaction AND marks the invoice paid, which
        // routes through InvoiceObserver → advancePeriod() → SubscriptionRenewed.
        Tashil::billing()->recordPayment(
            invoice: $invoice,
            gateway: 'stripe',
            transactionId: $charge->transactionId(),
            metadata: ['source' => 'auto-renewal'],
        );
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
