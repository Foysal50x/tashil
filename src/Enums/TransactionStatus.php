<?php

namespace Foysal50x\Tashil\Enums;

enum TransactionStatus: string
{
    case Pending = 'pending';
    case Success = 'success';
    case Failed = 'failed';
    case Refunded = 'refunded';
}
