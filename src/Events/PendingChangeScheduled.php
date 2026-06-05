<?php

declare(strict_types=1);

namespace Foysal50x\Tashil\Events;

use Foysal50x\Tashil\Models\Package;
use Foysal50x\Tashil\Models\Subscription;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PendingChangeScheduled
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Subscription $subscription,
        public Package $targetPackage,
        public \DateTimeInterface $effectiveAt,
    ) {}
}
