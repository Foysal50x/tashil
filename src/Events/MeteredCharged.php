<?php

namespace Foysal50x\Tashil\Events;

use Foysal50x\Tashil\Models\Feature;
use Foysal50x\Tashil\Models\Subscription;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatched after MeteredBilling::charge returned true and the
 * usage counter + log were written. Hosts listen to push notifications
 * to the user, update spend dashboards, etc.
 */
class MeteredCharged
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
