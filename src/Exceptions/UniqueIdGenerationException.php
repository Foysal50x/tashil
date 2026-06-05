<?php

declare(strict_types=1);

namespace Foysal50x\Tashil\Exceptions;

use RuntimeException;

/**
 * Thrown when a ShouldBeUnique id generator cannot produce an id that passes
 * its own isUnique() check within the attempt budget
 * (TokenizedIdGenerator::maxGenerationAttempts()).
 *
 * In practice this means the format token space is too small for the volume of
 * ids in the current bucket (birthday collisions) — widen the format with more
 * N / S / A tokens, or relax the uniqueness scope. Extends RuntimeException so
 * existing `catch (RuntimeException)` handlers keep working; catch the dedicated
 * type when you want to react to this specific failure.
 */
class UniqueIdGenerationException extends RuntimeException
{
    public static function afterMaxAttempts(string $generator, int $attempts): self
    {
        return new self(sprintf(
            '%s could not generate a unique id after %d attempts. '
            . 'Widen the format token space (e.g. more N/S/A tokens) or relax the uniqueness check.',
            $generator,
            $attempts,
        ));
    }
}
