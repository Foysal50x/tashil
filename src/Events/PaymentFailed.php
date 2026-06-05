<?php

declare(strict_types=1);

namespace Foysal50x\Tashil\Events;

use Foysal50x\Tashil\Models\Invoice;
use Foysal50x\Tashil\Models\Transaction;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A failed charge attempt was recorded against an invoice via
 * BillingService::recordFailedPayment. The invoice is left Pending so the
 * dunning cycle can keep retrying — this event is an audit signal, not a state
 * transition.
 */
class PaymentFailed
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Transaction $transaction,
        public Invoice $invoice,
    ) {}
}
