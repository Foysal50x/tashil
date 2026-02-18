<?php

namespace Foysal50x\Tashil\Enums;

enum Period: string
{
    case Day = 'day';
    case Week = 'week';
    case Month = 'month';
    case Year = 'year';
    case Lifetime = 'lifetime';
}
