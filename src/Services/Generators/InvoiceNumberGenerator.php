<?php

declare(strict_types=1);

namespace Foysal50x\Tashil\Services\Generators;

use Foysal50x\Tashil\Contracts\ShouldBeUnique;
use Foysal50x\Tashil\Models\Invoice;

class InvoiceNumberGenerator extends TokenizedIdGenerator implements ShouldBeUnique
{
    protected function prefix(): string
    {
        return (string) config('tashil.invoice.prefix', 'INV');
    }

    protected function format(): string
    {
        return (string) config('tashil.invoice.format', '#-YYMMDD-NNNNNN');
    }

    /**
     * `invoice_number` is globally unique; the pre-check fast-fails likely
     * collisions before the insert. The DB unique constraint remains the
     * real guarantee under concurrency.
     */
    public function isUnique(string $id): bool
    {
        return ! Invoice::query()->where('invoice_number', $id)->exists();
    }
}
