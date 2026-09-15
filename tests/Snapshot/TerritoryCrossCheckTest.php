<?php

declare(strict_types=1);

namespace Kaikei\EuVat\Tests\Snapshot;

use Kaikei\EuVat\RateClass;
use Kaikei\EuVat\Snapshot\TerritoryCrossCheck;
use Kaikei\EuVat\Tedb\CalendarDate;
use Kaikei\EuVat\Tedb\ParsedResponse;
use Kaikei\EuVat\Tedb\RateSample;
use Kaikei\EuVat\Tedb\ResponseParser;
use Kaikei\EuVat\Territory\TerritoryTable;
use PHPUnit\Framework\TestCase;

final class TerritoryCrossCheckTest extends TestCase
{
    private function check(): TerritoryCrossCheck
    {
        return new TerritoryCrossCheck(TerritoryTable::fromFile(__DIR__.'/../../data/territories.json'));
    }

    /**
     * The state the table is supposed to be in: everything TEDB currently names is either a
     * curated territory or a known scheme. If this ever fails, the world moved.
     */
    public function testTheRealResponseContainsNoUnrecognisedQualifiers(): void
    {
        $parsed = (new ResponseParser())->parse(file_get_contents(__DIR__.'/../fixtures/tedb-eu27-current.xml'));

        self::assertSame([], $this->check()->unrecognisedQualifiers($parsed));
    }

    public function testCanaryIslandsIsRecognisedFromTheCuratedTable(): void
    {
        $parsed = new ParsedResponse([
            new RateSample('ES', RateClass::STANDARD, '7.00', CalendarDate::parse('2026-01-01'), 'Canary Islands'),
        ]);

        self::assertSame([], $this->check()->unrecognisedQualifiers($parsed));
    }

    public function testImportIsAllowedAsASchemeRatherThanAPlace(): void
    {
        $parsed = new ParsedResponse([
            new RateSample('DE', RateClass::STANDARD, '19.00', CalendarDate::parse('2026-01-01'), 'Import'),
        ]);

        self::assertSame([], $this->check()->unrecognisedQualifiers($parsed));
    }

    public function testEitherHalfOfACompoundTerritoryNameIsRecognised(): void
    {
        // TEDB reports "Jungholz, Mittelberg" as one row; the table holds one entry for both.
        $parsed = new ParsedResponse([
            new RateSample('AT', RateClass::REDUCED, '19.00', CalendarDate::parse('2026-01-01'), 'Jungholz'),
            new RateSample('AT', RateClass::REDUCED, '19.00', CalendarDate::parse('2026-01-01'), 'Mittelberg'),
        ]);

        self::assertSame([], $this->check()->unrecognisedQualifiers($parsed));
    }

    /**
     * Regression, and the sharpest lesson in this file. Matching curated territory names against
     * arbitrary comment text once reclassified the NETHERLANDS' 9% medical-equipment rate as
     * Corsican — the row enumerates orthopaedic appliances including *corsets*, and "Corse" is a
     * substring of "corsets". The effect was to silently delete a country's real reduced rate.
     *
     * Two defences, and this asserts both: reclassification only ever touches rows TEDB itself
     * marked regional, and alias matching is word-boundary, not substring.
     */
    public function testACategorySpecificRateIsNeverMistakenForARegionalOne(): void
    {
        $parsed = (new ResponseParser())->parse(file_get_contents(__DIR__.'/../fixtures/tedb-eu27-current.xml'));
        $classified = $parsed->classifyTerritories(TerritoryTable::fromFile(__DIR__.'/../../data/territories.json'));

        $dutch = array_values(array_filter(
            $classified->reducedSamples(),
            static fn ($s): bool => 'NL' === $s->country,
        ));

        self::assertContains('9.00', array_map(static fn ($s): string => $s->percent, $dutch), "The Netherlands' 9% reduced rate must survive.");
    }

    public function testAnUnknownTerritoryIsReportedRatherThanIgnored(): void
    {
        $parsed = new ParsedResponse([
            new RateSample('PT', RateClass::STANDARD, '16.00', CalendarDate::parse('2026-01-01'), 'Some New Autonomous Region'),
        ]);

        self::assertSame(
            ['PT: Some New Autonomous Region (16.00%)'],
            $this->check()->unrecognisedQualifiers($parsed),
        );
    }

    public function testRepeatedQualifiersAreReportedOnce(): void
    {
        $parsed = new ParsedResponse([
            new RateSample('PT', RateClass::STANDARD, '16.00', CalendarDate::parse('2026-01-01'), 'Elsewhere'),
            new RateSample('PT', RateClass::STANDARD, '16.00', CalendarDate::parse('2026-07-01'), 'Elsewhere'),
        ]);

        self::assertCount(1, $this->check()->unrecognisedQualifiers($parsed));
    }
}
