<?php

declare(strict_types=1);

namespace Foysal50x\Tashil\Contracts;

use Foysal50x\Tashil\Models\Transaction;

interface TransactionRepositoryInterface
{
    /**
     * Record a new transaction row.
     */
    public function create(array $data): Transaction;

    /**
     * Find a transaction by its (gateway, transaction_id) reconciliation key.
     * Returns null when no such pair has been recorded yet. Drives webhook
     * idempotency — a re-delivered charge resolves to the row already written.
     */
    public function findByGatewayTransaction(string $gateway, string $transactionId): ?Transaction;

    /**
     * The most recent successful transaction recorded against an invoice — the
     * charge a refund would target. Null when the invoice has no settled charge.
     */
    public function latestSuccessfulForInvoice(int $invoiceId): ?Transaction;
}
