<?php

declare(strict_types=1);

namespace Kaikei\EuVat\Exception;

/**
 * The country is not in the snapshot at all.
 *
 * Note this is thrown for `NO`, `CH` and `GB`, which are genuinely absent from TEDB rather than
 * missing by accident. Consumers must treat them as out of scope explicitly; reading an absent
 * rate as zero is how a non-EU sale silently books as VAT-free when it should not.
 */
final class UnknownCountry extends \RuntimeException implements EuVatException
{
    public static function notInSnapshot(string $country): self
    {
        return new self(sprintf(
            'No VAT data for "%s". The snapshot covers the EU-27 only (Greece as EL); '
            .'NO, CH and GB are not published by TEDB and must be handled explicitly by the caller.',
            $country,
        ));
    }
}
