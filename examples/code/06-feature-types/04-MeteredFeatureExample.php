<?php

declare(strict_types=1);

namespace App\Http\Controllers\Features;

use App\Http\Controllers\Controller;
use Foysal50x\Tashil\Events\MeteredChargeRejected;
use Foysal50x\Tashil\Exceptions\MeteredBillingNotConfiguredException;
use Foysal50x\Tashil\Facades\Tashil;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * FEATURE TYPE 4 of 5 — METERED  (pay-as-you-go)
 * ==============================================
 *
 * Catalog (CatalogSeeder):
 *     Tashil::feature('ai-tokens')->metered()->create();
 *     // pro plan → ->feature($aiTokens, value: '0.0002')   value = UNIT PRICE
 *
 * Semantics:
 *   - Each consume charges  units × unit_price  through YOUR MeteredBilling
 *     implementation (see 00-setup/WalletMeteredBilling). Tashil owns the
 *     counter; it never owns the balance.
 *   - CHARGE BEFORE WRITE: useFeature() calls MeteredBilling::charge() FIRST.
 *     Only on a `true` return does it advance the counter + write the log +
 *     fire MeteredCharged. On `false` (insufficient balance) nothing is written
 *     and MeteredChargeRejected fires — useFeature returns false.
 *   - No limit_value; the gate is the wallet balance, not a cap.
 *   - REQUIRES a bound provider. With none, NullMeteredBilling's read paths
 *     safe-deny but charge() THROWS MeteredBillingNotConfiguredException —
 *     don't ship metered features without binding a provider.
 *
 * Use it for: LLM tokens, per-minute transcription, per-GB egress, SMS.
 */
class MeteredFeatureExample extends Controller
{
    /**
     * POST /ai/complete  — meter an AI completion by token count.
     *
     * PASS AN IDEMPOTENCY KEY. Metered calls move money; if the request is
     * retried (client retry, queue redelivery) the same key lets your provider
     * dedupe and charge exactly once. Without it, retries double-charge.
     */
    public function complete(Request $request): JsonResponse
    {
        $user   = $request->user();
        $tokens = (float) $request->integer('tokens');

        // A stable key for THIS logical operation. Use the request id, a job
        // uuid, or a domain id — anything that is identical across retries.
        $idempotencyKey = $request->header('Idempotency-Key')
            ?? "ai-complete:{$user->getKey()}:{$request->input('request_id')}";

        try {
            // charge(units × unit_price) runs first; counter advances only if it
            // returns true.
            $charged = $user->useFeature('ai-tokens', $tokens, $idempotencyKey);
        } catch (MeteredBillingNotConfiguredException $e) {
            // No real provider bound (NullMeteredBilling). A 500 is correct —
            // we must not silently drop a billable call.
            report($e);

            return response()->json(['message' => 'Billing is not configured.'], 500);
        }

        if (! $charged) {
            // Insufficient balance → MeteredChargeRejected already fired.
            return response()->json([
                'message' => 'Insufficient balance for this request. Please top up.',
                'balance' => $this->balance($user),
            ], 402); // 402 Payment Required
        }

        // ... run the model, return the completion ...

        return response()->json([
            'ok'      => true,
            'charged' => $tokens,
            'balance' => $this->balance($user),
        ]);
    }

    /**
     * GET /ai/can-afford?tokens=1500  — pre-flight WITHOUT charging.
     *
     * check() delegates to MeteredBilling::hasSufficientBalance for metered
     * features. Read-only and advisory — the real gate is still useFeature.
     */
    public function canAfford(Request $request): JsonResponse
    {
        $user   = $request->user();
        $tokens = (float) $request->integer('tokens', 1);

        return response()->json([
            'affordable' => Tashil::usage()->check($user->subscription(), 'ai-tokens', $tokens),
            'balance'    => $this->balance($user),
        ]);
    }

    /**
     * Current spendable balance, in the subscription's currency, straight from
     * your provider.
     */
    private function balance($user): float
    {
        $sub = $user->subscription();

        return app(\Foysal50x\Tashil\Contracts\MeteredBilling::class)
            ->getBalance($user, $sub->package->currency);
    }
}

/*
|------------------------------------------------------------------------------
| Wiring & events
|------------------------------------------------------------------------------
|
| Bind your provider (00-setup/AppServiceProvider):
|     $this->app->bind(MeteredBilling::class, WalletMeteredBilling::class);
|   — or implement MeteredBilling directly on the User/Team model (Pattern A),
|     then no binding is needed.
|
| React to outcomes:
|     Event::listen(MeteredCharged::class, fn ($e) =>
|         // $e->units, $e->unitPrice, $e->amount, $e->currency — usage receipt
|     );
|     Event::listen(MeteredChargeRejected::class, fn (MeteredChargeRejected $e) =>
|         // out-of-balance — prompt a top-up, throttle the feature
|     );
|
| Don'ts:
|   - reportStorage('ai-tokens', ...) is REJECTED (returns false): absolute-set
|     can't express per-delta charges.
|   - Orphan charge: if the provider charged but the DB write then failed,
|     useFeature() RE-THROWS (after Log::critical with the idempotency key) so
|     you can reconcile. Let it surface — don't catch-and-ignore that path.
*/
