<?php

declare(strict_types=1);

namespace Foysal50x\Tashil\Events;

use Foysal50x\Tashil\Models\Invoice;
use Foysal50x\Tashil\Models\Transaction;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A successful payment was recorded against an invoice via
 * BillingService::recordPayment. Carries both the new transaction (the audit
 * row) and the invoice it settled. Distinct from InvoicePaid, which fires from
 * the InvoiceObserver on the status flip — this one always pairs with a
 * recorded transaction.
 */
class PaymentRecorded
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Transaction $transaction,
        public Invoice $invoice,
    ) {}
}
