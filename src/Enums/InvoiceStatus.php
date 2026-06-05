<?php

declare(strict_types=1);

namespace Foysal50x\Tashil\Enums;

enum InvoiceStatus: string
{
    case Draft = 'draft';
    case Pending = 'pending';
    case Paid = 'paid';
    case Void = 'void';
    case Refunded = 'refunded';
}
