<?php

declare(strict_types=1);

namespace Foysal50x\Tashil\Services\Generators;

class TransactionIdGenerator extends TokenizedIdGenerator
{
    protected function prefix(): string
    {
        return (string) config('tashil.transaction.prefix', 'TXN');
    }

    protected function format(): string
    {
        return (string) config('tashil.transaction.format', '#-YYMMDD-NNNNNNAA');
    }
}
