<?php

declare(strict_types=1);

namespace Foysal50x\Tashil\Enums;

enum ResetPeriod: string
{
    case Never = 'never';
    case Daily = 'daily';
    case Weekly = 'weekly';
    case Monthly = 'monthly';
    case Yearly = 'yearly';

    public function isNever(): bool
    {
        return $this === self::Never;
    }
}
