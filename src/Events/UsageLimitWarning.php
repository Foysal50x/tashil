<?php

namespace Foysal50x\Tashil\Events;

use Foysal50x\Tashil\Models\Subscription;
use Foysal50x\Tashil\Models\Feature;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class UsageLimitWarning
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Subscription $subscription, 
        public Feature $feature,
        public float $usage,
        public float $limit
    ) {}
}
