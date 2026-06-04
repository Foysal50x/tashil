<?php

namespace Foysal50x\Tashil\Services\Generators;

use Foysal50x\Tashil\Contracts\ShouldBeUnique;
use Foysal50x\Tashil\Exceptions\UniqueIdGenerationException;
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
        $id = $this->build();

        // Generators that don't opt into uniqueness return the rendered id
        // straight away — no verification, no extra query.
        if (! $this instanceof ShouldBeUnique) {
            return $id;
        }

        // Opted in: re-render until isUnique() accepts an id, bounded by
        // maxGenerationAttempts() so an exhausted format space (or a buggy
        // isUnique) fails loudly instead of looping forever.
        for ($attempt = 1; ! $this->isUnique($id); $attempt++) {
            if ($attempt >= $this->maxGenerationAttempts()) {
                throw UniqueIdGenerationException::afterMaxAttempts(static::class, $attempt);
            }

            $id = $this->build();
        }

        return $id;
    }

    /**
     * Render one id from the format template. Pure: each call may differ only
     * by its random tokens, so it is safe to call repeatedly when retrying for
     * uniqueness.
     */
    protected function build(): string
    {
        $processed = $this->replaceRandomTokens($this->format());

        return str_replace(
            ['#', 'YY', 'MM', 'DD'],
            [$this->prefix(), now()->format('y'), now()->format('m'), now()->format('d')],
            $processed,
        );
    }

    /**
     * Maximum number of render attempts before a ShouldBeUnique generator
     * gives up. Override to widen the budget for tight formats.
     */
    protected function maxGenerationAttempts(): int
    {
        return 10;
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
