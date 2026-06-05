<?php

declare(strict_types=1);

namespace Foysal50x\Tashil\Events;

use Foysal50x\Tashil\Models\Invoice;
use Foysal50x\Tashil\Models\Transaction;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A refund the host already executed at the gateway was recorded against a
 * transaction via BillingService::recordRefund. The transaction carries the
 * cumulative `refunded_amount`; `status === Refunded` means it was refunded in
 * full (and the invoice was moved to Refunded). Tashil records the refund — it
 * never moves the money.
 */
class PaymentRefunded
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Transaction $transaction,
        public Invoice $invoice,
    ) {}
}
