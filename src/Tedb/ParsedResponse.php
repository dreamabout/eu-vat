<?php

declare(strict_types=1);

namespace Kaikei\EuVat\Tedb;

use Kaikei\EuVat\RateClass;

/**
 * The rate samples in one TEDB response, split by what they are evidence of.
 *
 * Qualifier samples are kept rather than discarded: they are how the curated territory table
 * gets cross-checked against reality, and an unrecognised qualifier appearing is the signal
 * that something changed in the world rather than in our code.
 */
final class ParsedResponse
{
    /**
     * @param list<RateSample> $samples every usable rate row, qualified and not
     */
    public function __construct(private readonly array $samples)
    {
    }

    /** @return list<RateSample> */
    public function all(): array
    {
        return $this->samples;
    }

    /** @return list<RateSample> the countries' own standard rates */
    public function standardSamples(): array
    {
        return array_values(array_filter(
            $this->samples,
            static fn (RateSample $s): bool => RateClass::STANDARD === $s->rateClass && !$s->isQualified(),
        ));
    }

    /** @return list<RateSample> the countries' own reduced, super-reduced and parking rates */
    public function reducedSamples(): array
    {
        return array_values(array_filter(
            $this->samples,
            static fn (RateSample $s): bool => RateClass::STANDARD !== $s->rateClass && !$s->isQualified(),
        ));
    }

    /** @return list<RateSample> rows naming a territory or special scheme */
    public function qualifierSamples(): array
    {
        return array_values(array_filter($this->samples, static fn (RateSample $s): bool => $s->isQualified()));
    }
}
