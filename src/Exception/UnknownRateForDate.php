<?php

declare(strict_types=1);

namespace Kaikei\EuVat\Exception;

/**
 * No rate is known for that country on that date.
 *
 * Most often the date precedes the snapshot's window. The package refuses to answer rather than
 * returning its oldest known rate, because the failure mode of guessing is silent and expensive:
 * a report restating a period from before the data begins would look entirely plausible.
 */
final class UnknownRateForDate extends \RuntimeException implements EuVatException
{
    public static function beforeWindow(string $country, \DateTimeImmutable $date, \DateTimeImmutable $windowStart): self
    {
        return new self(sprintf(
            'No VAT rate known for %s on %s: the snapshot only covers dates from %s onwards. '
            .'Refusing to extrapolate backwards — regenerate the snapshot with an earlier --from if you need this period.',
            $country,
            $date->format('Y-m-d'),
            $windowStart->format('Y-m-d'),
        ));
    }

    public static function noRate(string $country, \DateTimeImmutable $date): self
    {
        return new self(sprintf('No VAT rate known for %s on %s.', $country, $date->format('Y-m-d')));
    }
}
