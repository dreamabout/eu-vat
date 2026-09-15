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
