<?php

declare(strict_types=1);

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Services\PaymentGateway;
use Foysal50x\Tashil\Enums\InvoiceKind;
use Foysal50x\Tashil\Exceptions\SubscriptionException;
use Foysal50x\Tashil\Models\Invoice;
use Foysal50x\Tashil\Models\Package;
use Foysal50x\Tashil\Models\Subscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * PAID SUBSCRIPTION CHECKOUT — the activate-on-payment model.
 *
 * A priced plan with requires_payment = true (the default) does NOT grant
 * access on subscribe(). Instead:
 *
 *   subscribe(enterprise)        →  status = Pending  (NO access, NO period)
 *      └─ Tashil issues an `initial` invoice (status = Pending)             ◀── this controller
 *   host charges the card, then invoice.markAsPaid()                        ◀── PaymentWebhookController
 *      └─ InvoiceObserver → SubscriptionService::activate()
 *          status = Active, period anchored to paid_at, counters reanchored
 *          → SubscriptionActivated event                                    ◀── ProvisionOnActivation
 *
 * Key gotcha: while Pending, `$user->subscription()` returns NULL because a
 * pending sub is not "valid". Always work from the object subscribe() returns
 * (or query $user->subscriptions() directly) until it is activated.
 */
class CheckoutController extends Controller
{
    public function __construct(private readonly PaymentGateway $gateway) {}

    /**
     * POST /billing/checkout/enterprise
     *
     * Create the pending subscription + initial invoice and hand the client a
     * payment intent to confirm. No access is granted yet.
     */
    public function start(Request $request): JsonResponse
    {
        $user       = $request->user();
        $enterprise = Package::where('slug', 'enterprise')->firstOrFail();

        try {
            // Priced + requires_payment ⇒ status Pending, initial invoice issued.
            $subscription = $user->subscribe($enterprise);
        } catch (SubscriptionException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        // The initial invoice that subscribe() just issued — this is the bill
        // that, once paid, flips the subscription to Active.
        $invoice = $this->initialInvoiceFor($subscription);

        // Create a gateway payment intent. Crucially, stash the Tashil invoice
        // id in the intent metadata so the webhook can find it again. Tashil
        // moves no money — your gateway does, then tells Tashil via markAsPaid.
        $intent = $this->gateway->createPaymentIntent(
            amount: (float) $invoice->amount,
            currency: $invoice->currency,
            metadata: ['tashil_invoice_id' => $invoice->id],
        );

        return response()->json([
            'subscription_status' => $subscription->status->value,  // "pending"
            'has_access'          => $subscription->isValid(),       // false
            'invoice' => [
                'id'       => $invoice->id,
                'number'   => $invoice->invoice_number,
                'amount'   => $invoice->amount,
                'currency' => $invoice->currency,
                'due_date' => $invoice->due_date,
                'status'   => $invoice->status->value,               // "pending"
            ],
            // Front-end confirms this with the gateway SDK; settlement arrives
            // via webhook (see PaymentWebhookController).
            'client_secret' => $intent->clientSecret,
        ], 201);
    }

    /**
     * Fetch the initial invoice for a freshly-created pending subscription.
     * We can't use $user->subscription() here — a Pending sub is not "valid"
     * and would resolve to null — so we read straight off the subscription.
     */
    private function initialInvoiceFor(Subscription $subscription): Invoice
    {
        return $subscription->invoices()
            ->where('kind', InvoiceKind::Initial)
            ->latest('id')
            ->firstOrFail();
    }
}
