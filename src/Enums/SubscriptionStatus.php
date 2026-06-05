<?php

declare(strict_types=1);

namespace Foysal50x\Tashil\Enums;

enum SubscriptionStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case OnTrial = 'on_trial';
    case PastDue = 'past_due';
    case Paused = 'paused';
    case PendingCancellation = 'pending_cancellation';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
    case Suspended = 'suspended';
}
