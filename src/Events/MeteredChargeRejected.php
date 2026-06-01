<?php

namespace Foysal50x\Tashil\Events;

use Foysal50x\Tashil\Models\Feature;
use Foysal50x\Tashil\Models\Subscription;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatched when MeteredBilling::charge returned false. The
 * counter is NOT advanced and no usage log row was written. Hosts use
 * this to surface "insufficient balance" / "top up" prompts to the user.
 */
class MeteredChargeRejected
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Subscription $subscription,
        public Feature $feature,
        public float $units,
        public float $unitPrice,
        public float $amount,
        public string $currency,
    ) {}
}
