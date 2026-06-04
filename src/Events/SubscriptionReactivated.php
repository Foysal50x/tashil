<?php

namespace Foysal50x\Tashil\Events;

use Foysal50x\Tashil\Models\Invoice;
use Foysal50x\Tashil\Models\Subscription;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A lapsed subscription (past_due / suspended / expired) was brought back to
 * `active` — typically because the host collected a previously failed
 * payment. `$invoice` is the invoice whose payment triggered recovery, when
 * the recovery came through the invoice-paid path.
 */
class SubscriptionReactivated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Subscription $subscription,
        public ?Invoice $invoice = null,
    ) {}
}
