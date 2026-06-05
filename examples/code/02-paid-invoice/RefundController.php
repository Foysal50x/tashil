<?php

declare(strict_types=1);

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Services\PaymentGateway;
use Foysal50x\Tashil\Facades\Tashil;
use Foysal50x\Tashil\Models\Invoice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * REFUNDS — record a refund the gateway already executed.
 *
 * Same division of labor as everywhere else: the HOST moves the money (calls the
 * gateway's refund API), then tells Tashil what happened. Tashil records it
 * against the original Transaction and reflects the Invoice state — it never
 * issues a gateway refund itself.
 *
 *     $gatewayRefund = $gateway->refund($charge->transaction_id, $amount);   // host moves money
 *     Tashil::billing()->recordRefund($charge, amount: $amount, reason: ...); // Tashil records it
 *
 * recordRefund():
 *   - accumulates `refunded_amount` on the transaction (supports partials),
 *   - stamps `refunded_at` + `refund_reason`,
 *   - flips the transaction to Refunded once fully refunded — and only then
 *     moves the invoice to Refunded (a partial refund leaves it Paid),
 *   - fires PaymentRefunded.
 */
class RefundController extends Controller
{
    public function __construct(private readonly PaymentGateway $gateway) {}

    /**
     * POST /admin/invoices/{invoice}/refund   { "amount": 12.50, "reason": "…" }
     *
     * Omit `amount` for a full refund of the remaining balance.
     */
    public function store(Request $request, Invoice $invoice): JsonResponse
    {
        // The successful charge we're refunding. One invoice can have several
        // transactions (a failed attempt, then a success) — the billing API
        // returns the latest Success.
        $charge = Tashil::billing()->successfulTransaction($invoice);

        if ($charge === null) {
            return response()->json(['message' => 'No successful charge to refund'], 422);
        }

        $amount = $request->filled('amount') ? (float) $request->input('amount') : null;

        // 1. Host moves the money at the gateway (Tashil never does this).
        $gatewayRefund = $this->gateway->refund(
            transactionId: $charge->transaction_id,
            amount: $amount,                       // null → gateway refunds the full charge
        );

        if (! $gatewayRefund->successful()) {
            return response()->json(['message' => 'Gateway refund failed'], 502);
        }

        // 2. Tashil records the refund + reflects the invoice state.
        $transaction = Tashil::billing()->recordRefund(
            transaction: $charge,
            amount: $amount,
            reason: (string) $request->input('reason', 'admin refund'),
            metadata: ['gateway_refund_id' => $gatewayRefund->id()],
        );

        return response()->json([
            'transaction_status' => $transaction->status->value,   // success (partial) | refunded (full)
            'refunded_amount'    => (float) $transaction->refunded_amount,
            'invoice_status'     => $invoice->refresh()->status->value,
        ]);
    }
}
