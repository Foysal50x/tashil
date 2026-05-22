<?php

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
        $generator = app($generatorClass);

        $transaction->transaction_id = $generator->generate();
    }
}
