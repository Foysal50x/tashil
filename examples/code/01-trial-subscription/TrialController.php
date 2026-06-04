<?php

declare(strict_types=1);

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use Foysal50x\Tashil\Enums\InvoiceKind;
use Foysal50x\Tashil\Exceptions\SubscriptionException;
use Foysal50x\Tashil\Facades\Tashil;
use Foysal50x\Tashil\Models\Invoice;
use Foysal50x\Tashil\Models\Package;
use Foysal50x\Tashil\Models\Subscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * COMPLETE TRIAL FLOW — start → use → (remind) → convert → first bill.
 *
 *   subscribe(withTrial)         →  OnTrial   (access NOW, no invoice)
 *      │  customer uses the app for 14 days
 *      │  tashil:mark-trials-ending fires TrialEnding 3 days out  (reminder email)
 *      ▼
 *   convertTrial()               →  Active    (access continues) + an `initial` invoice
 *      │  host charges the saved card
 *      ▼
 *   invoice.markAsPaid()         →  first paid period anchored
 *
 * If the customer never converts, tashil:expire-trials flips OnTrial → Expired
 * (access lost) — see RegisterTrialCommands.php. Tashil NEVER auto-converts on
 * payment; conversion is always a host decision.
 */
class TrialController extends Controller
{
    /**
     * POST /billing/trial/start
     *
     * Start a 14-day trial of Pro. The trial length comes from the package's
     * trial_days (set in the catalog seeder) — you don't pass it here.
     */
    public function start(Request $request): JsonResponse
    {
        $user = $request->user();
        $pro = Package::where('slug', 'pro')->firstOrFail();

        try {
            // withTrial: true → status OnTrial, access granted immediately,
            // NO invoice is issued. trial_started_at / trial_ends_at are set
            // from the package's trial_days. Billing happens only at convert.
            $subscription = $user->subscribe($pro, withTrial: true);
        } catch (SubscriptionException $e) {
            // Thrown as `alreadySubscribed` when the subscriber already has a
            // live subscription. Move plans with changePlan()/switchPlan()
            // instead of subscribing twice. Catch the dedicated type, not text.
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json([
            'status'        => $subscription->status->value,   // "on_trial"
            'on_trial'      => $subscription->isOnTrial(),      // true
            'trial_ends_at' => $subscription->trial_ends_at,
            'access'        => $subscription->isValid(),        // true — OnTrial has access
        ], 201);
    }

    /**
     * GET /billing/trial/status
     *
     * `onTrial()` is strict: status === OnTrial AND trial_ends_at is in the
     * future. A trial whose clock has run out but hasn't been swept by the
     * cron yet reports false here.
     */
    public function status(Request $request): JsonResponse
    {
        $user = $request->user();
        $sub = $user->subscription();           // resolves the valid subscription

        return response()->json([
            'on_trial'       => $user->onTrial(),
            'days_remaining' => $sub?->trial_ends_at
                ? (int) now()->diffInDays($sub->trial_ends_at, false)
                : null,
            'trial_ends_at' => $sub?->trial_ends_at,
            // While on trial every entitled feature already works:
            'can_use_sso'    => $user->hasFeature('sso'),
            'api_calls_left' => $user->featureRemaining('api-calls'),
        ]);
    }

    /**
     * POST /billing/trial/convert
     *
     * Convert the running trial to a paid subscription. Call this when the
     * customer adds a payment method or clicks "upgrade now".
     *
     * convertTrial():
     *   - status OnTrial → Active, sets trial_converted_at,
     *   - re-anchors current_period_* to NOW (no free remainder of the trial),
     *   - issues the first `initial` invoice for a priced plan (this is what
     *     you then charge). For a free plan, no invoice.
     */
    public function convert(Request $request): JsonResponse
    {
        $user = $request->user();
        $sub = $user->subscription();

        if ($sub === null || ! $sub->isOnTrial()) {
            return response()->json(['message' => 'No active trial to convert.'], 422);
        }

        $sub = Tashil::subscription()->convertTrial($sub);

        // The first invoice was issued by convertTrial(). Charge it now.
        $invoice = $sub->invoices()
            ->where('kind', InvoiceKind::Initial)
            ->latest('id')
            ->first();

        if ($invoice !== null) {
            // Hand off to your gateway. On a synchronous charge you can pay it
            // inline; on an async gateway you'd return a client secret and let
            // the webhook call markAsPaid() (see 02-paid-invoice).
            $this->chargeAndSettle($invoice);
        }

        return response()->json([
            'status'             => $sub->status->value,        // "active"
            'trial_converted_at' => $sub->trial_converted_at,
            'invoice_id'         => $invoice?->id,
            'invoice_paid'       => $invoice?->fresh()->isPaid(),
        ]);
    }

    /**
     * POST /billing/trial/cancel
     *
     * Cancel during the trial. Immediate cancel ends the trial now; a grace
     * cancel keeps access until trial_ends_at and lets the customer resume.
     */
    public function cancel(Request $request): JsonResponse
    {
        $user = $request->user();

        // Returns null if there is nothing to cancel.
        $sub = $user->cancelSubscription(immediate: true, reason: 'Abandoned trial');

        return response()->json([
            'cancelled' => $sub !== null,
            'status'    => $sub?->status->value,
        ]);
    }

    /**
     * Demo settlement. In production this lives in a gateway service: charge
     * the card, record a Transaction, then markAsPaid() — the InvoiceObserver
     * does the rest (anchors the period, fires SubscriptionActivated).
     */
    private function chargeAndSettle(Invoice $invoice): void
    {
        // $charge = $gateway->charge($invoice->amount, $invoice->currency, ...);
        // if ($charge->successful()) { ... record Transaction ... }
        $invoice->markAsPaid();
    }
}
