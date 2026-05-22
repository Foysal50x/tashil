<?php

namespace Foysal50x\Tashil\Services\Generators;

use Illuminate\Support\Str;

/**
 * Shared base for format-token id generators (invoice number, transaction
 * id, …). Subclasses only declare which config keys to read; the token
 * grammar and rendering live here.
 *
 * Format tokens:
 * - #  : Prefix
 * - YY : Year (2 digits)
 * - MM : Month (2 digits)
 * - DD : Day (2 digits)
 * - N  : Random Digit (0-9)
 * - S  : Random Letter (A-Z)
 * - A  : Random Alphanumeric (A-Z0-9)
 */
abstract class TokenizedIdGenerator
{
    /**
     * Prefix substituted for `#` in the format string. Subclasses read
     * this from their own config namespace.
     */
    abstract protected function prefix(): string;

    /**
     * Format template using the token grammar documented above.
     */
    abstract protected function format(): string;

    public function generate(): string
    {
        $processed = $this->replaceRandomTokens($this->format());

        return str_replace(
            ['#', 'YY', 'MM', 'DD'],
            [$this->prefix(), now()->format('y'), now()->format('m'), now()->format('d')],
            $processed,
        );
    }

    protected function replaceRandomTokens(string $format): string
    {
        $result = '';
        $length = strlen($format);

        for ($i = 0; $i < $length; $i++) {
            $char = $format[$i];

            $result .= match ($char) {
                'N'     => (string) mt_rand(0, 9),
                'S'     => chr(rand(65, 90)),
                'A'     => strtoupper(Str::random(1)),
                default => $char,
            };
        }

        return $result;
    }
}
