<?php

declare(strict_types=1);

namespace Foysal50x\Tashil\Services\Generators;

use Foysal50x\Tashil\Contracts\ShouldBeUnique;
use Foysal50x\Tashil\Models\Transaction;

class TransactionIdGenerator extends TokenizedIdGenerator implements ShouldBeUnique
{
    /**
     * The gateway the generated id will be stored under. Uniqueness is checked
     * within this gateway only — the same scope as the DB constraint — so the
     * id is generated to be free for *this* gateway. `TransactionObserver`
     * passes the row's gateway; defaults to `manual` (the column default) for
     * callers that don't supply one.
     */
    public function __construct(protected string $gateway = 'manual') {}

    protected function prefix(): string
    {
        return (string) config('tashil.transaction.prefix', 'TXN');
    }

    protected function format(): string
    {
        return (string) config('tashil.transaction.format', '#-YYMMDD-NNNNNNAA');
    }

    /**
     * The DB uniqueness scope is the composite `(gateway, transaction_id)`, and
     * this pre-check mirrors it exactly by scoping to the same gateway. The same
     * `transaction_id` legitimately exists under a *different* gateway (e.g. a
     * manual id that happens to match a Stripe charge id), so checking it
     * globally would wrongly reject a perfectly valid id; scoping to the gateway
     * avoids that. The composite constraint stays the real guarantee under
     * concurrency.
     */
    public function isUnique(string $id): bool
    {
        return ! Transaction::query()
            ->where('gateway', $this->gateway)
            ->where('transaction_id', $id)
            ->exists();
    }
}
