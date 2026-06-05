<?php

declare(strict_types=1);

namespace Foysal50x\Tashil\Enums;

enum TransactionStatus: string
{
    case Pending = 'pending';
    case Success = 'success';
    case Failed = 'failed';
    case Refunded = 'refunded';
}
