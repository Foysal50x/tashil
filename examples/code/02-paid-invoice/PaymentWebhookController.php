<?php

declare(strict_types=1);

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use Foysal50x\Tashil\Enums\TransactionStatus;
use Foysal50x\Tashil\Models\Invoice;
use Foysal50x\Tashil\Models\Transaction;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * GATEWAY WEBHOOK — turns a successful charge into "invoice paid".
 *
 * This is the host's half of the activate-on-payment handshake. Tashil already
 * issued the initial invoice (Pending). When the gateway confirms the charge,
 * we:
 *   1. find the invoice by the id we stashed in the intent metadata,
 *   2. record a Transaction (audit + idempotency),
 *   3. call $invoice->markAsPaid().
 *
 * Step 3 is the only thing Tashil reacts to: InvoiceObserver sees the status
 * flip to Paid, and because kind = Initial + subscription = Pending, it routes
 * to SubscriptionService::activate() — anchoring the period to paid_at and
 * firing SubscriptionActivated. We never call activate() ourselves.
 *
 * Webhooks are delivered at-least-once, so this MUST be idempotent. The
 * UNIQUE(gateway, transaction_id) constraint on tashil_transactions does the
 * heavy lifting: a duplicate delivery hits the constraint and we treat it as
 * "already recorded".
 */
class PaymentWebhookController extends Controller
{
    /**
     * POST /webhooks/payment   (gateway → us; verify the signature in middleware)
     */
    public function handle(Request $request): JsonResponse
    {
        $event = $request->input('type');

        // Only settle on a successful charge. Failures flow through dunning
        // (see 04-suspend) for renewals, or simply leave the invoice Pending.
        if ($event !== 'payment_intent.succeeded') {
            return response()->json(['ignored' => $event]);
        }

        $payload   = $request->input('data.object', []);
        $invoiceId = $payload['metadata']['tashil_invoice_id'] ?? null;
        $chargeId  = $payload['id'] ?? null;            // e.g. "ch_3P..."

        $invoice = $invoiceId ? Invoice::find($invoiceId) : null;
        if ($invoice === null) {
            // Unknown invoice — ack so the gateway stops retrying, but log it.
            return response()->json(['message' => 'Invoice not found'], 200);
        }

        // Already settled (a re-delivered webhook for an invoice we paid): ack.
        if ($invoice->isPaid()) {
            return response()->json(['message' => 'Already paid'], 200);
        }

        $this->recordPaymentAndSettle(
            invoice: $invoice,
            gateway: 'stripe',
            gatewayTxnId: (string) $chargeId,
            amount: (float) ($payload['amount_received'] ?? $invoice->amount),
        );

        return response()->json(['message' => 'Settled'], 200);
    }

    /**
     * Record the gateway transaction, then mark the invoice paid. Both run in
     * one DB transaction so we never end up with "paid invoice, no audit row".
     */
    private function recordPaymentAndSettle(
        Invoice $invoice,
        string $gateway,
        string $gatewayTxnId,
        float $amount,
    ): void {
        DB::transaction(function () use ($invoice, $gateway, $gatewayTxnId, $amount) {
            try {
                // Pass the gateway-supplied id through verbatim. UNIQUE(gateway,
                // transaction_id) makes a duplicate webhook safe to retry.
                Transaction::create([
                    'invoice_id'     => $invoice->id,
                    'gateway'        => $gateway,
                    'transaction_id' => $gatewayTxnId,
                    'status'         => TransactionStatus::Success,
                    'amount'         => $amount,
                    'metadata'       => ['source' => 'webhook'],
                ]);
            } catch (UniqueConstraintViolationException) {
                // This exact charge was already recorded by a prior delivery.
                // Nothing more to do — the first delivery also paid the invoice.
                return;
            }

            // The single line Tashil cares about. InvoiceObserver takes it from
            // here: Initial + Pending → activate() → Active + SubscriptionActivated.
            $invoice->markAsPaid();
        });
    }
}
