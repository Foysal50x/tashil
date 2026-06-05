<?php

declare(strict_types=1);

namespace Foysal50x\Tashil\Events;

use Foysal50x\Tashil\Models\Invoice;
use Foysal50x\Tashil\Models\Subscription;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A subscription transitioned from `pending` to `active` — either because
 * its initial invoice was paid, or because it was a free/offline plan that
 * activates without payment. `$invoice` is null for the latter.
 */
class SubscriptionActivated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Subscription $subscription,
        public ?Invoice $invoice = null,
    ) {}
}
