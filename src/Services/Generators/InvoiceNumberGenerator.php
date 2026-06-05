<?php

declare(strict_types=1);

namespace Foysal50x\Tashil\Services\Generators;

class InvoiceNumberGenerator extends TokenizedIdGenerator
{
    protected function prefix(): string
    {
        return (string) config('tashil.invoice.prefix', 'INV');
    }

    protected function format(): string
    {
        return (string) config('tashil.invoice.format', '#-YYMMDD-NNNNNN');
    }
}
