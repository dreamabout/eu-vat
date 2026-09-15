<?php

declare(strict_types=1);

namespace Kaikei\EuVat\Exception;

/**
 * The place is inside the EU VAT area but in a territory whose rates this package has not
 * verified against a primary source — Madeira and the Azores, which apply their own rates
 * under Portuguese law.
 *
 * Returning the mainland rate here would be a plausible-looking wrong answer, which is the
 * worst kind. Refusing says exactly what is missing and who can supply it.
 */
final class TerritoryRateUnverified extends \RuntimeException implements EuVatException
{
    public static function forTerritory(string $name, string $memberState): self
    {
        return new self(sprintf(
            '%s applies its own VAT rates, which this package has not verified against a primary '
            .'source. Returning %s\'s mainland rate would be wrong. Verify the current rates and '
            .'add a rate_override to data/territories.json, or handle this territory in the caller.',
            $name,
            $memberState,
        ));
    }
}
