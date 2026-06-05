<?php

declare(strict_types=1);

namespace Foysal50x\Tashil\Services\Generators;

use Foysal50x\Tashil\Contracts\ShouldBeUnique;
use Foysal50x\Tashil\Models\Transaction;

class TransactionIdGenerator extends TokenizedIdGenerator implements ShouldBeUnique
{
    protected function prefix(): string
    {
        return (string) config('tashil.transaction.prefix', 'TXN');
    }

    protected function format(): string
    {
        return (string) config('tashil.transaction.format', '#-YYMMDD-NNNNNNAA');
    }

    /**
     * The DB uniqueness scope is `(gateway, transaction_id)`, but generated
     * ids are only stamped for gateway-less manual/cash entries, so a global
     * `transaction_id` pre-check is correct and slightly stricter. The
     * composite constraint stays the real guarantee under concurrency.
     */
    public function isUnique(string $id): bool
    {
        return ! Transaction::query()->where('transaction_id', $id)->exists();
    }
}
