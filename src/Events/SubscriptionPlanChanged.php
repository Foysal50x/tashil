<?php

namespace Foysal50x\Tashil\Events;

use Foysal50x\Tashil\Models\Invoice;
use Foysal50x\Tashil\Models\Package;
use Foysal50x\Tashil\Models\Subscription;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * An in-place plan change (upgrade/lateral) was applied to the SAME
 * subscription row — distinct from SubscriptionSwitched, which cancels the
 * old subscription and creates a new one. The current period and usage
 * counters are carried forward; `$prorationInvoice` is the prorated-delta
 * invoice when one was issued (null for lateral/dust changes).
 */
class SubscriptionPlanChanged
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Subscription $subscription,
        public Package $oldPackage,
        public Package $newPackage,
        public float $prorationAmount = 0.0,
        public ?Invoice $prorationInvoice = null,
    ) {}
}
