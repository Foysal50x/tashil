<?php

namespace Foysal50x\Tashil\Services\Providers;

use Foysal50x\Tashil\Contracts\MeteredBilling;
use Foysal50x\Tashil\Contracts\Subscribable;
use Foysal50x\Tashil\Exceptions\MeteredBillingNotConfiguredException;

/**
 * Default MeteredBilling used when the host hasn't bound a real one.
 *
 * Read paths (getBalance, hasSufficientBalance) return safe defaults so
 * that advisory gating in middleware / Blade directives degrades to
 * "deny" instead of throwing 500s on every render.
 *
 * charge() throws because the absence of a real implementation means a
 * metered consume cannot move money. Failing loud here points the host
 * at the line that needs configuration; failing soft would silently
 * drop revenue events.
 */
class NullMeteredBilling implements MeteredBilling
{
    public function getBalance(Subscribable $subscriber, string $currency): float
    {
        return 0.0;
    }

    public function hasSufficientBalance(Subscribable $subscriber, string $currency, float $amount): bool
    {
        return false;
    }

    public function charge(Subscribable $subscriber, string $currency, float $amount, array $context = []): bool
    {
        throw MeteredBillingNotConfiguredException::forFeature(
            $context['feature_slug'] ?? '<unknown>',
        );
    }
}
