<?php

declare(strict_types=1);

namespace Foysal50x\Tashil\Tests\Fixtures;

use Foysal50x\Tashil\Contracts\MeteredBilling;
use Foysal50x\Tashil\Contracts\Subscribable;

/**
 * Test double for the MeteredBilling contract.
 *
 * Tracks every call so tests can assert idempotency keys, charge amounts,
 * and currencies. setBalance() seeds the wallet; chargeShouldSucceed()
 * toggles the next charge outcome explicitly when balance-based logic
 * isn't enough for a given scenario.
 */
class FakeMeteredBilling implements MeteredBilling
{
    public array $calls = [];

    public function __construct(
        public float $balance = 1000.0,
        public ?bool $forceResult = null,
    ) {}

    public function getBalance(Subscribable $subscriber, string $currency): float
    {
        $this->calls[] = ['method' => 'getBalance', 'currency' => $currency];

        return $this->balance;
    }

    public function hasSufficientBalance(Subscribable $subscriber, string $currency, float $amount): bool
    {
        $this->calls[] = ['method' => 'hasSufficientBalance', 'currency' => $currency, 'amount' => $amount];

        return $this->balance >= $amount;
    }

    public function charge(Subscribable $subscriber, string $currency, float $amount, array $context = []): bool
    {
        $this->calls[] = [
            'method'   => 'charge',
            'currency' => $currency,
            'amount'   => $amount,
            'context'  => $context,
        ];

        $accepted = $this->forceResult ?? ($this->balance >= $amount);
        if ($accepted) {
            $this->balance -= $amount;
        }

        return $accepted;
    }

    public function chargeCallCount(): int
    {
        return count(array_filter($this->calls, fn ($c) => $c['method'] === 'charge'));
    }
}
