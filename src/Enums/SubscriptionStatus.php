<?php

namespace Foysal50x\Tashil\Enums;

enum SubscriptionStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case OnTrial = 'on_trial';
    case PastDue = 'past_due';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
    case Suspended = 'suspended';
}
