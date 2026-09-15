<?php

declare(strict_types=1);

namespace Kaikei\EuVat\Tedb;

/**
 * Parses TEDB's `xs:date` values as calendar dates, discarding any timezone offset.
 *
 * TEDB stamps rows as `2024-09-01+02:00`. That offset says "this date is expressed in the
 * publishing jurisdiction's timezone", not "this is an instant". PHP, given the whole string,
 * disagrees: `new DateTimeImmutable('2024-09-01+02:00')` is midnight at +02:00, and converting
 * it to UTC yields 2024-08-31T22:00Z. A rate change would then take effect a day early, and the
 * error would surface only for orders placed on the boundary date itself — one day a year, per
 * change, in one country.
 *
 * Taking the date part and pinning it to midnight UTC removes the question entirely: every date
 * in this package is a calendar date, compared against other calendar dates.
 */
final class CalendarDate
{
    private const PATTERN = '/^(\d{4})-(\d{2})-(\d{2})/';

    public static function parse(string $value): \DateTimeImmutable
    {
        $trimmed = trim($value);

        if (1 !== preg_match(self::PATTERN, $trimmed, $matches)) {
            throw new \InvalidArgumentException(sprintf(
                'Expected a TEDB date like "2024-09-01" or "2024-09-01+02:00", got "%s".',
                $value,
            ));
        }

        $date = \DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            sprintf('%s-%s-%s', $matches[1], $matches[2], $matches[3]),
            new \DateTimeZone('UTC'),
        );

        if (false === $date) {
            throw new \InvalidArgumentException(sprintf('"%s" is not a valid calendar date.', $value));
        }

        return $date;
    }
}
