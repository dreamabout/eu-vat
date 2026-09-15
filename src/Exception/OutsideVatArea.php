<?php

declare(strict_types=1);

namespace Kaikei\EuVat\Exception;

/**
 * The place is a territory outside the EU VAT area, so no EU VAT rate applies to it.
 *
 * Thrown rather than returned as a zero rate, because the two are not the same fact: 0% is a
 * rate that was charged, while this is a supply the VAT Directive does not reach at all. A
 * caller that books them identically will misreport both.
 */
final class OutsideVatArea extends \RuntimeException implements EuVatException
{
    public function __construct(
        public readonly string $territory,
        public readonly string $legalBasis,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function territory(string $name, string $country, ?string $postalCode, string $legalBasis): self
    {
        return new self($name, $legalBasis, sprintf(
            '%s (%s%s) lies outside the EU VAT area under %s. No EU VAT rate applies; '
            .'this is not the same as a 0%% rate and must not be booked as one.',
            $name,
            $country,
            null === $postalCode ? '' : ' '.$postalCode,
            $legalBasis,
        ));
    }
}
