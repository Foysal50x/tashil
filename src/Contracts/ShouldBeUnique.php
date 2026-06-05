<?php

declare(strict_types=1);

namespace Foysal50x\Tashil\Contracts;

/**
 * Opt-in uniqueness for a tokenized id generator.
 *
 * A generator that extends TokenizedIdGenerator and implements this contract
 * has every generated id verified by isUnique() before it is returned;
 * generators that do not implement it return the freshly rendered id as-is
 * (no extra work, no DB round-trip).
 *
 * Typical implementation checks the target column:
 *
 *     class InvoiceNumberGenerator extends TokenizedIdGenerator implements ShouldBeUnique
 *     {
 *         public function isUnique(string $id): bool
 *         {
 *             return ! Invoice::where('invoice_number', $id)->exists();
 *         }
 *     }
 */
interface ShouldBeUnique
{
    /**
     * Whether the given generated id is free to use (not already taken).
     * Return false to make the generator render and re-check another id.
     */
    public function isUnique(string $id): bool;
}
