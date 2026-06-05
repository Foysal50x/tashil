<?php

declare(strict_types=1);

namespace Foysal50x\Tashil\Tests\Fixtures;

use Foysal50x\Tashil\Contracts\MeteredBilling;
use Foysal50x\Tashil\Contracts\Subscribable;

/**
 * Demonstrates the self-implementing pattern: the subscriber model itself
 * satisfies the MeteredBilling contract, so no separate service binding
 * is needed. The wallet/balance lives on the model.
 *
 * Used by tests to prove that UsageService::resolveMeteredBilling prefers
 * a self-impl subscriber over the container binding. Because morphTo
 * relations rehydrate fresh instances, balances and call records are
 * keyed by primary key in static maps rather than instance properties.
 */
class SelfBillingUser extends User implements MeteredBilling
{
    /** @var array<int, float> */
    public static array $balances = [];

    /** @var array<int, array<int, array{currency: string, amount: float, context: array}>> */
    public static array $chargeCalls = [];

    public static function reset(): void
    {
        self::$balances = [];
        self::$chargeCalls = [];
    }

    public function setBalance(float $balance): void
    {
        self::$balances[$this->getKey()] = $balance;
    }

    public function balance(): float
    {
        return self::$balances[$this->getKey()] ?? 0.0;
    }

    /**
     * @return array<int, array{currency: string, amount: float, context: array}>
     */
    public function chargeCalls(): array
    {
        return self::$chargeCalls[$this->getKey()] ?? [];
    }

    public function getBalance(Subscribable $subscriber, string $currency): float
    {
        return $this->balance();
    }

    public function hasSufficientBalance(Subscribable $subscriber, string $currency, float $amount): bool
    {
        return $this->balance() >= $amount;
    }

    public function charge(Subscribable $subscriber, string $currency, float $amount, array $context = []): bool
    {
        self::$chargeCalls[$this->getKey()][] = compact('currency', 'amount', 'context');

        if ($this->balance() < $amount) {
            return false;
        }

        self::$balances[$this->getKey()] = $this->balance() - $amount;

        return true;
    }
}
