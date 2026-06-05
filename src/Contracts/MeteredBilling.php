<?php

declare(strict_types=1);

namespace Foysal50x\Tashil\Contracts;

/**
 * Host-implemented bridge to the account/balance system that funds
 * Metered features.
 *
 * Tashil never owns balances or charges money. When a Metered feature is
 * consumed, UsageService asks the bound MeteredBilling to charge
 * `units × unit_price` against the subscriber's balance — if the charge
 * succeeds, the counter advances and a usage log is written; if not, the
 * call returns false and nothing is recorded.
 *
 * Bind a concrete implementation in a service provider:
 *
 *   $this->app->bind(
 *       \Foysal50x\Tashil\Contracts\MeteredBilling::class,
 *       \App\Billing\WalletMeteredBilling::class,
 *   );
 *
 * If no implementation is bound, charge() throws
 * MeteredBillingNotConfiguredException so the host sees the misconfiguration
 * immediately instead of silently dropping usage. Read methods (getBalance,
 * hasSufficientBalance) of the default NullMeteredBilling return safe
 * defaults (0.0, false) so advisory gating in middleware and Blade
 * directives degrades to "deny" instead of 500.
 */
interface MeteredBilling
{
    /**
     * Current available balance for this subscriber in the given currency.
     * Used by UIs and pre-flight checks; not required for charge().
     */
    public function getBalance(Subscribable $subscriber, string $currency): float;

    /**
     * Pre-flight sufficiency check. Subject to TOCTOU — never trust this
     * as the gate; charge() is authoritative.
     */
    public function hasSufficientBalance(Subscribable $subscriber, string $currency, float $amount): bool;

    /**
     * Attempt to deduct $amount from the subscriber's balance.
     *
     * MUST be idempotent on `$context['idempotency_key']`. The key comes
     * from one of two sources:
     *
     *   1. The caller passed an explicit token to `useFeature($slug,
     *      $amount, $idempotencyKey)` — typically the request ID, a
     *      queued-job UUID, or a domain operation identifier. The same
     *      token reaching this method twice represents the same logical
     *      consume and MUST NOT double-charge.
     *
     *   2. The caller passed no token, so Tashil generated a fresh UUID
     *      for this attempt. Two separate `useFeature()` calls then
     *      receive different UUIDs — useful only for retries inside a
     *      single attempt (e.g. provider-internal HTTP retries).
     *
     * Implementations should return:
     *   true  — funds reserved/deducted successfully (or the same key
     *           was previously charged and that prior result was true).
     *   false — insufficient balance, gateway declined, or any other
     *           recoverable refusal. UsageService will reject the consume
     *           and fire MeteredChargeRejected.
     *
     * Throw only for non-recoverable / programmer errors (bad currency,
     * malformed context). Those bubble up.
     *
     * @param  array{idempotency_key: string, subscription_id: int, feature_id: int, feature_slug: string, units: float, unit_price: float}  $context
     */
    public function charge(
        Subscribable $subscriber,
        string $currency,
        float $amount,
        array $context = [],
    ): bool;
}
