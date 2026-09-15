<?php

declare(strict_types=1);

namespace Kaikei\EuVat\Tedb;

use Kaikei\EuVat\RateClass;
use Kaikei\EuVat\Territory\TerritoryTable;

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

    /**
     * Reclassifies samples whose comment names a curated territory.
     *
     * The shape rule in {@see ResponseParser} catches "VAT - Canary Islands - " but not
     * Austria's bare "Jungholz, Mittelberg", which looks exactly like a category note such as
     * "Import only". Left unclassified, Jungholz's 19% is published as though Austria had a 19%
     * reduced band for everyone — a wrong rate, presented as fact, for a country that does not
     * have one.
     *
     * Matching is conservative: a comment must name the territory, not merely mention it, so a
     * paragraph of legal prose that happens to contain "Madeira" does not reclassify anything.
     */
    public function classifyTerritories(TerritoryTable $territories): self
    {
        $classified = [];
        foreach ($this->samples as $sample) {
            if ($sample->isQualified() || null === $sample->comment) {
                $classified[] = $sample;

                continue;
            }

            $territory = $territories->territoryNamedIn($sample->comment);
            $classified[] = null === $territory ? $sample : $sample->asQualified($territory->name);
        }

        return new self($classified);
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
