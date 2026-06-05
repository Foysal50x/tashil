<?php

declare(strict_types=1);

namespace Foysal50x\Tashil\Events;

use Foysal50x\Tashil\Models\Subscription;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dunning exhausted its retries — the subscription was suspended and access
 * is now cut. The host may still recover it by collecting payment, which
 * routes through reactivate().
 */
class SubscriptionSuspended
{
    use Dispatchable, SerializesModels;

    public function __construct(public Subscription $subscription) {}
}
