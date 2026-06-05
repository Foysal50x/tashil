<?php

declare(strict_types=1);

namespace Foysal50x\Tashil\Enums;

enum UsageOperation: string
{
    case Consume = 'consume';
    case Reset = 'reset';
    case Adjust = 'adjust';
    case Report = 'report';
}
