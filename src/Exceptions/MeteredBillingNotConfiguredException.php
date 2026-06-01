<?php

namespace Foysal50x\Tashil\Exceptions;

use RuntimeException;

/**
 * Thrown when a Metered feature is consumed but no MeteredBilling
 * implementation has been bound. Tashil never assumes the host has a
 * balance/wallet system — the host must opt in by binding the contract.
 */
class MeteredBillingNotConfiguredException extends RuntimeException
{
    public static function forFeature(string $featureSlug): self
    {
        return new self(sprintf(
            "Metered feature '%s' was consumed but no MeteredBilling is bound. "
            . 'Bind Foysal50x\\Tashil\\Contracts\\MeteredBilling in a service provider '
            . 'or disable the feature.',
            $featureSlug,
        ));
    }
}
