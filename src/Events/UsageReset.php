<?php

namespace Foysal50x\Tashil\Events;

use Foysal50x\Tashil\Models\Feature;
use Foysal50x\Tashil\Models\Subscription;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class UsageReset
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Subscription $subscription,
        public Feature $feature,
        public float $previousUsage,
    ) {}
}
