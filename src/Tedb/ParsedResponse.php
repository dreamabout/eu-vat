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
     * This is cosmetic, not structural: TEDB's `category = REGION` marker has already
     * identified which rows are regional, and the parser has already set a qualifier from the
     * comment. All this does is replace TEDB's wording ("Azores Autonomous Region", "For
     * Corsica") with the curated name, so the snapshot reads consistently.
     */
    public function classifyTerritories(TerritoryTable $territories): self
    {
        $classified = [];
        foreach ($this->samples as $sample) {
            // ONLY already-qualified rows are considered. TEDB's own `category = REGION` marker
            // identifies a regional rate structurally, so there is nothing to infer here — and
            // inferring is actively dangerous. Matching curated names against arbitrary comments
            // once reclassified the Netherlands' 9% medical-equipment rate as Corsican, because
            // the row lists orthopaedic *corsets* and "Corse" is a substring of "corsets". That
            // silently deletes a country's real reduced rate.
            if (!$sample->isQualified() || null === $sample->comment) {
                $classified[] = $sample;

                continue;
            }

            $territory = $territories->territoryNamedIn((string) $sample->qualifier);
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

    /** @return list<RateSample> rows naming a territory, region or special scheme */
    public function qualifierSamples(): array
    {
        return array_values(array_filter($this->samples, static fn (RateSample $s): bool => $s->isQualified()));
    }
}
