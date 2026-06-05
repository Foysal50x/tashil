<?php

declare(strict_types=1);

namespace Foysal50x\Tashil\Events;

use Foysal50x\Tashil\Models\Invoice;
use Foysal50x\Tashil\Models\Subscription;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A subscription's renewal invoice went unpaid past its due date and the
 * subscription entered the dunning cycle (`past_due`). `$invoice` is the
 * unpaid invoice and `$attempt` is the current dunning attempt number.
 */
class SubscriptionPastDue
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Subscription $subscription,
        public Invoice $invoice,
        public int $attempt,
    ) {}
}
