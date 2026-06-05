<?php

declare(strict_types=1);

namespace Foysal50x\Tashil\Repositories;

use Foysal50x\Tashil\Contracts\TransactionRepositoryInterface;
use Foysal50x\Tashil\Enums\TransactionStatus;
use Foysal50x\Tashil\Models\Transaction;

class EloquentTransactionRepository implements TransactionRepositoryInterface
{
    public function create(array $data): Transaction
    {
        return Transaction::create($data);
    }

    public function findByGatewayTransaction(string $gateway, string $transactionId): ?Transaction
    {
        return Transaction::query()
            ->where('gateway', $gateway)
            ->where('transaction_id', $transactionId)
            ->first();
    }

    public function latestSuccessfulForInvoice(int $invoiceId): ?Transaction
    {
        return Transaction::query()
            ->where('invoice_id', $invoiceId)
            ->where('status', TransactionStatus::Success)
            ->latest('id')
            ->first();
    }
}
