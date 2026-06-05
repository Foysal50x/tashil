<?php

declare(strict_types=1);

namespace Foysal50x\Tashil\Events;

use Foysal50x\Tashil\Models\Package;
use Foysal50x\Tashil\Models\Subscription;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SubscriptionSwitched
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Subscription $oldSubscription,
        public Subscription $newSubscription,
        public Package $oldPackage,
        public Package $newPackage,
    ) {}
}
