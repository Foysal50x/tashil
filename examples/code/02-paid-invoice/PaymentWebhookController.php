<?php

declare(strict_types=1);

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use Foysal50x\Tashil\Facades\Tashil;
use Foysal50x\Tashil\Models\Invoice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GATEWAY WEBHOOK — turns a successful charge into "invoice paid".
 *
 * This is the host's half of the activate-on-payment handshake. Tashil already
 * issued the initial invoice (Pending). When the gateway confirms the charge,
 * we hand the result to Tashil with a single call:
 *
 *     Tashil::billing()->recordPayment($invoice, gateway: 'stripe', transactionId: $chargeId)
 *
 * recordPayment() writes the Transaction audit row AND marks the invoice paid in
 * one DB transaction — so there is never a "paid invoice, no audit row". Marking
 * it paid is what Tashil reacts to: InvoiceObserver sees Initial + Pending and
 * routes to SubscriptionService::activate(), anchoring the period to paid_at and
 * firing SubscriptionActivated. We never call activate() ourselves.
 *
 * Webhooks are delivered at-least-once, so this MUST be idempotent — and it is,
 * for free: recordPayment() is idempotent on (gateway, transaction_id). A
 * re-delivered charge resolves to the row already written and does not settle
 * the invoice twice. (Tashil never touches the gateway — it only records what
 * yours reports.)
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

        $payload = $request->input('data.object', []);
        $invoiceId = $payload['metadata']['tashil_invoice_id'] ?? null;
        $chargeId = $payload['id'] ?? null;            // e.g. "ch_3P..."

        $invoice = $invoiceId ? Invoice::find($invoiceId) : null;
        if ($invoice === null) {
            // Unknown invoice — ack so the gateway stops retrying, but log it.
            return response()->json(['message' => 'Invoice not found'], 200);
        }

        // Record the gateway transaction and settle the invoice in one call.
        // Safe to run on a re-delivered webhook — idempotent on (gateway,
        // transaction_id).
        Tashil::billing()->recordPayment(
            invoice: $invoice,
            gateway: 'stripe',
            transactionId: (string) $chargeId,
            amount: (float) ($payload['amount_received'] ?? $invoice->amount),
            gatewayResponse: $payload,
            metadata: ['source' => 'webhook'],
        );

        return response()->json(['message' => 'Settled'], 200);
    }
}
