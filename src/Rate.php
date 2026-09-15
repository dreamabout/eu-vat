<?php

declare(strict_types=1);

namespace Kaikei\EuVat;

/**
 * One VAT rate, valid over a half-open date interval.
 *
 * The interval is `[validFrom, validTo)` — inclusive of its first day, exclusive of its last.
 * This is the only choice that makes a change date unambiguous: Finland went from 24% to 25.5%
 * on 2024-09-01, and an order placed that day is charged 25.5%. An inclusive upper bound would
 * make both rates valid on the boundary, and whichever the lookup happened to return first
 * would be the answer.
 *
 * `percent` is a decimal string ('25.50'), never a float. See the class docblock on
 * {@see VatCalculator} for why that matters beyond taste.
 */
final class Rate
{
    public function __construct(
        /** ISO-3166-1 alpha-2, with Greece as `EL` (its VAT/OSS code). */
        public readonly string $country,
        public readonly RateClass $rateClass,
        /** Percent, e.g. '25.50'. Not a fraction. */
        public readonly string $percent,
        public readonly \DateTimeImmutable $validFrom,
        /** Exclusive upper bound; null means open-ended (still in force). */
        public readonly ?\DateTimeImmutable $validTo,
    ) {
    }

    /** The rate as a fraction ('0.2550'), for callers that multiply rather than percent. */
    public function fraction(): string
    {
        return bcdiv($this->percent, '100', 6);
    }

    /** Whether this rate was in force on the given date, per the half-open interval. */
    public function coversDate(\DateTimeImmutable $date): bool
    {
        $day = $date->format('Y-m-d');

        if ($day < $this->validFrom->format('Y-m-d')) {
            return false;
        }

        return null === $this->validTo || $day < $this->validTo->format('Y-m-d');
    }

    /** True when the two rates are numerically equal, so '7', '7.0' and '7.000' agree. */
    public function isRate(string $percent): bool
    {
        return 0 === bccomp($this->percent, $percent, 6);
    }
}
