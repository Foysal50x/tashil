<?php

declare(strict_types=1);

namespace App\Billing;

use Foysal50x\Tashil\Contracts\ShouldBeUnique;
use Foysal50x\Tashil\Models\Invoice;
use Foysal50x\Tashil\Services\Generators\TokenizedIdGenerator;

/**
 * A custom invoice-number generator that GUARANTEES uniqueness.
 *
 * The built-in InvoiceNumberGenerator renders an id from the format string and
 * returns it — collisions are caught only at insert time by the DB. Implement
 * `ShouldBeUnique` and the base TokenizedIdGenerator::generate() will instead
 * re-render until isUnique() accepts the id (bounded by maxGenerationAttempts).
 *
 * Opt in by pointing config at this class:
 *
 *     // config/tashil.php
 *     'invoice' => [
 *         'prefix'    => 'INV',
 *         'format'    => '#-YYMMDD-NNNNNN',
 *         'generator' => \App\Billing\UniqueInvoiceNumberGenerator::class,
 *     ],
 *
 * InvoiceObserver::creating resolves the generator from that config and calls
 * generate() whenever an invoice is created without an invoice_number — so this
 * applies to every issued invoice with no other wiring.
 */
class UniqueInvoiceNumberGenerator extends TokenizedIdGenerator implements ShouldBeUnique
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
     * Return false to make the generator render and re-check another id.
     *
     * Keep this a cheap, INDEXED lookup — it runs on the invoice `creating`
     * path for every id rendered. Include soft-deleted rows (withTrashed) so a
     * number can never be reused even after an invoice is deleted.
     */
    public function isUnique(string $id): bool
    {
        return ! Invoice::withTrashed()->where('invoice_number', $id)->exists();
    }

    /**
     * Optional: widen the retry budget when the format token space is tight.
     * Default is 10. If a free id isn't found within the budget, generate()
     * throws UniqueIdGenerationException — widen the format, don't just retry.
     */
    protected function maxGenerationAttempts(): int
    {
        return 25;
    }
}
