<?php

declare(strict_types=1);

namespace Foysal50x\Tashil\Observers;

use Foysal50x\Tashil\Models\Transaction;
use Foysal50x\Tashil\Services\Generators\TransactionIdGenerator;
use Illuminate\Support\Facades\Config;

class TransactionObserver
{
    public function creating(Transaction $transaction): void
    {
        if (! empty($transaction->transaction_id)) {
            return;
        }

        $generatorClass = Config::get('tashil.transaction.generator', TransactionIdGenerator::class);

        // Pass the row's gateway so the generator checks uniqueness within the
        // composite (gateway, transaction_id) scope. Falls back to the column
        // default when the caller didn't set one.
        $generator = app($generatorClass, ['gateway' => $transaction->gateway ?? 'manual']);

        $transaction->transaction_id = $generator->generate();
    }
}
