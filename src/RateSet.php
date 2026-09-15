<?php

declare(strict_types=1);

namespace Kaikei\EuVat;

/**
 * Every rate a country had in force on one date: the standard rate, plus whatever reduced,
 * super-reduced and parking rates sat alongside it.
 *
 * Deliberately says nothing about WHICH goods attract the reduced rate. TEDB publishes CN/CPA
 * category lists for exactly that, and they are dropped during parsing: mapping a product to a
 * category is a catalogue problem, and a package that answered it halfway would be worse than
 * one that declines to.
 */
final class RateSet
{
    /**
     * @param list<Rate> $reduced reduced, super-reduced and parking rates, in no guaranteed order
     */
    public function __construct(
        public readonly string $country,
        public readonly Rate $standard,
        public readonly array $reduced,
    ) {
    }

    /** @return list<Rate> the standard rate first, then the rest */
    public function all(): array
    {
        return [$this->standard, ...$this->reduced];
    }

    /** Whether the country charged this exact rate on the date, in any band. */
    public function hasRate(string $percent): bool
    {
        foreach ($this->all() as $rate) {
            if ($rate->isRate($percent)) {
                return true;
            }
        }

        return false;
    }

    /** The band a rate falls in, or null when the country did not charge it at all. */
    public function classOf(string $percent): ?RateClass
    {
        foreach ($this->all() as $rate) {
            if ($rate->isRate($percent)) {
                return $rate->rateClass;
            }
        }

        return null;
    }
}
