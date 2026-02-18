<?php

namespace Foysal50x\Tashil\Services\Generators;

use Illuminate\Support\Str;

class InvoiceNumberGenerator
{
    /**
     * Generate a new invoice number based on configuration.
     * 
     * Format tokens:
     * - #: Prefix
     * - YY: Year (2 digits)
     * - MM: Month (2 digits)
     * - DD: Day (2 digits)
     * - N: Random Digit (0-9)
     * - S: Random specific Letter (A-Z)
     * - A: Random Alphanumeric (A-Z0-9)
     */
    public function generate(): string
    {
        $prefix = config('tashil.invoice.prefix', 'INV');
        $format = config('tashil.invoice.format', '#-YYMMDD-NNNNNN');

        // Step 1: Replace Random Tokens (N, S, A)
        // We handle this first to avoid replacing characters in the Prefix or Date string
        // if they happen to contain these letters (though unlikely for date).
        // But Prefix `SAN` would have `S`, `A`, `N` replaced if we did prefix first.
        $processed = $this->replaceRandomTokens($format);

        // Step 2: Replace Date and Prefix tokens
        return str_replace(
            ['#', 'YY', 'MM', 'DD'],
            [$prefix, now()->format('y'), now()->format('m'), now()->format('d')],
            $processed
        );
    }

    /**
     * Replace random tokens N, S, A with appropriate random characters.
     */
    protected function replaceRandomTokens(string $format): string
    {
        $result = '';
        $length = strlen($format);

        for ($i = 0; $i < $length; $i++) {
            $char = $format[$i];

            $result .= match ($char) {
                'N' => (string) mt_rand(0, 9),
                'S' => chr(rand(65, 90)), // A-Z
                'A' => strtoupper(Str::random(1)), // Alphanumeric
                default => $char,
            };
        }
        
        return $result;
    }
}
