<?php

declare(strict_types=1);

namespace App\Billing;

use App\Models\Wallet;
use Foysal50x\Tashil\Contracts\MeteredBilling;
use Foysal50x\Tashil\Contracts\Subscribable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Host-side implementation of metered billing.
 *
 * Tashil owns subscription state and usage counters but NEVER moves money for
 * metered features — it asks this class to debit a balance before it advances
 * the counter (invariant: "charge before write, never after"). You own the
 * wallet/balance table; Tashil only calls these three methods.
 *
 * This is "Pattern B" (container-bound). Register it in a service provider:
 *
 *     // AppServiceProvider::register()
 *     $this->app->bind(MeteredBilling::class, WalletMeteredBilling::class);
 *
 * "Pattern A" is to implement MeteredBilling directly on your User/Team model
 * instead — then no binding is needed. Pick one; both are supported and can
 * even coexist per subscriber type.
 *
 * The default when nothing is bound is NullMeteredBilling, whose read paths
 * safe-deny (balance 0, never sufficient) so feature checks degrade to "off"
 * instead of crashing — but charge() throws, because silently dropping a
 * money-moving call is worse than failing loudly.
 */
class WalletMeteredBilling implements MeteredBilling
{
    /**
     * Current spendable balance for the subscriber in the given currency.
     * Used by check() / @feature / the EnsureFeature middleware to decide
     * whether to even show the feature. Read-only — must never throw on a
     * missing wallet; return 0.0 to safe-deny.
     */
    public function getBalance(Subscribable $subscriber, string $currency): float
    {
        return (float) Wallet::where('owner_type', $subscriber->getSubscriberType())
            ->where('owner_id', $subscriber->getSubscriberKey())
            ->where('currency', $currency)
            ->value('balance') ?? 0.0;
    }

    /**
     * Pre-flight: can the subscriber afford `$amount` right now? Advisory
     * only — the real gate is charge(). Keep it side-effect free.
     */
    public function hasSufficientBalance(Subscribable $subscriber, string $currency, float $amount): bool
    {
        return $this->getBalance($subscriber, $currency) >= $amount;
    }

    /**
     * THE money-moving call. Tashil calls this FIRST; only a `true` return
     * lets it write the counter + usage log + MeteredCharged event.
     *
     * Contract you must honor:
     *  - Debit atomically and only if the balance covers it; return false on
     *    insufficient funds (Tashil then fires MeteredChargeRejected and
     *    useFeature() returns false — nothing is written).
     *  - DEDUPE on $context['idempotency_key']. The same key arriving twice
     *    (an app-level retry, a re-delivered job) must debit exactly once and
     *    return the original outcome. Without this, retries double-charge.
     *
     * $context carries: idempotency_key, subscription_id, feature_id,
     * feature_slug, units, unit_price.
     */
    public function charge(Subscribable $subscriber, string $currency, float $amount, array $context = []): bool
    {
        $idempotencyKey = $context['idempotency_key'] ?? null;

        return DB::transaction(function () use ($subscriber, $currency, $amount, $idempotencyKey, $context) {
            // 1. Idempotency: if we already processed this key, replay the result.
            if ($idempotencyKey !== null) {
                $existing = DB::table('wallet_charges')->where('idempotency_key', $idempotencyKey)->first();
                if ($existing !== null) {
                    return (bool) $existing->succeeded;
                }
            }

            // 2. Lock the wallet row so concurrent charges can't oversell.
            $wallet = Wallet::where('owner_type', $subscriber->getSubscriberType())
                ->where('owner_id', $subscriber->getSubscriberKey())
                ->where('currency', $currency)
                ->lockForUpdate()
                ->first();

            $succeeded = $wallet !== null && (float) $wallet->balance >= $amount;

            if ($succeeded) {
                $wallet->decrement('balance', $amount);
            }

            // 3. Record the attempt under the idempotency key for replay.
            if ($idempotencyKey !== null) {
                DB::table('wallet_charges')->insert([
                    'idempotency_key' => $idempotencyKey,
                    'subscription_id' => $context['subscription_id'] ?? null,
                    'feature_slug'    => $context['feature_slug'] ?? null,
                    'currency'        => $currency,
                    'amount'          => $amount,
                    'succeeded'       => $succeeded,
                    'created_at'      => now(),
                ]);
            }

            if (! $succeeded) {
                Log::info('Metered charge declined (insufficient balance)', $context + ['currency' => $currency, 'amount' => $amount]);
            }

            return $succeeded;
        });
    }
}
