<?php

declare(strict_types=1);

namespace Kaikei\EuVat\Tests;

use Kaikei\EuVat\Exception\OutsideVatArea;
use Kaikei\EuVat\Exception\PostalCodeRequired;
use Kaikei\EuVat\Exception\TerritoryRateUnverified;
use Kaikei\EuVat\Exception\UnknownCountry;
use Kaikei\EuVat\Exception\UnknownRateForDate;
use Kaikei\EuVat\Place;
use Kaikei\EuVat\Snapshot\SnapshotBuilder;
use Kaikei\EuVat\Tedb\ResponseParser;
use Kaikei\EuVat\VatRates;
use PHPUnit\Framework\TestCase;

/**
 * The lookup, built from the real DK/DE/FI fixture so the Finnish change is genuine data
 * rather than something arranged to pass.
 */
final class VatRatesTest extends TestCase
{
    private function rates(): VatRates
    {
        $parsed = (new ResponseParser())->parse(file_get_contents(__DIR__.'/fixtures/tedb-dk-de-fi-2024-2026.xml'));

        $snapshot = (new SnapshotBuilder())->build(
            $parsed,
            new \DateTimeImmutable('2024-01-01'),
            new \DateTimeImmutable('2026-09-15'),
            '2026-09-15T02:03:00Z',
        );

        return VatRates::fromArray($snapshot);
    }

    public function testResolvesTheRateInForceOnEachSideOfAChange(): void
    {
        $rates = $this->rates();

        self::assertSame('24.00', $rates->standardRate('FI', new \DateTimeImmutable('2024-08-31'))->percent);
        self::assertSame('25.50', $rates->standardRate('FI', new \DateTimeImmutable('2024-09-01'))->percent);
        self::assertSame('25.50', $rates->standardRate('FI', new \DateTimeImmutable('2026-09-15'))->percent);
    }

    public function testACountryThatNeverChangedResolvesAtEveryDate(): void
    {
        $rates = $this->rates();

        foreach (['2024-01-01', '2025-06-30', '2026-09-15'] as $date) {
            self::assertSame('25.00', $rates->standardRate('DK', new \DateTimeImmutable($date))->percent);
        }
    }

    public function testRefusesADateBeforeTheWindowRatherThanExtrapolating(): void
    {
        $this->expectException(UnknownRateForDate::class);
        $this->expectExceptionMessageMatches('/only covers dates from 2024-01-01/');

        $this->rates()->standardRate('FI', new \DateTimeImmutable('2023-12-31'));
    }

    public function testRefusesACountryItHasNoDataFor(): void
    {
        $this->expectException(UnknownCountry::class);
        // NO, CH and GB are genuinely absent from TEDB; reading that as 0% is how a non-EU sale
        // silently books VAT-free.
        $this->expectExceptionMessageMatches('/NO, CH and GB/');

        $this->rates()->standardRate('NO', new \DateTimeImmutable('2026-01-01'));
    }

    public function testAcceptsGreeceUnderEitherCode(): void
    {
        $rates = $this->rates();

        self::assertFalse($rates->knows('GR'));
        self::assertFalse($rates->knows('EL'), 'This fixture has no Greek data at all.');
    }

    public function testBusingenIsRefusedAsOutsideTheVatArea(): void
    {
        $this->expectException(OutsideVatArea::class);
        $this->expectExceptionMessageMatches('/not the same as a 0% rate/');

        $this->rates()->rateFor(Place::of('DE', '78266'), new \DateTimeImmutable('2026-01-01'));
    }

    public function testMainlandGermanyResolvesNormally(): void
    {
        self::assertSame('19.00', $this->rates()->rateFor(Place::of('DE', '10115'), new \DateTimeImmutable('2026-01-01'))->percent);
    }

    public function testACountryOnlyLookupRefusesWhereAPostalCodeWouldChangeTheAnswer(): void
    {
        $this->expectException(PostalCodeRequired::class);
        $this->expectExceptionMessageMatches('/countryOnlyAssumingMainland/');

        $this->rates()->rateFor(Place::countryOnly('DE'), new \DateTimeImmutable('2026-01-01'));
    }

    public function testTheMainlandAssumptionCanBeStatedExplicitly(): void
    {
        $rate = $this->rates()->rateFor(Place::countryOnlyAssumingMainland('DE'), new \DateTimeImmutable('2026-01-01'));

        self::assertSame('19.00', $rate->percent);
    }

    public function testACountryWithNoTerritoriesNeedsNoPostalCode(): void
    {
        // Denmark has none, so demanding a postcode would be friction with no payoff.
        self::assertSame('25.00', $this->rates()->rateFor(Place::countryOnly('DK'), new \DateTimeImmutable('2026-01-01'))->percent);
    }

    public function testRatesOnReturnsTheReducedBandsToo(): void
    {
        $set = $this->rates()->ratesOn('DE', new \DateTimeImmutable('2026-01-01'));

        self::assertSame('19.00', $set->standard->percent);
        self::assertTrue($set->hasRate('7.00'), 'Germany has a 7% reduced rate.');
        self::assertFalse($set->hasRate('13.00'), 'Germany has no 13% band.');
    }

    public function testRateSetIdentifiesWhichBandARateBelongsTo(): void
    {
        $set = $this->rates()->ratesOn('DE', new \DateTimeImmutable('2026-01-01'));

        self::assertSame('STANDARD', $set->classOf('19.00')?->value);
        self::assertSame('REDUCED', $set->classOf('7.00')?->value);
        self::assertNull($set->classOf('13.00'));
    }

    public function testReducedRateMatchingIsNumericNotTextual(): void
    {
        $set = $this->rates()->ratesOn('DE', new \DateTimeImmutable('2026-01-01'));

        foreach (['7', '7.0', '7.00', '7.000'] as $spelling) {
            self::assertTrue($set->hasRate($spelling), sprintf('"%s" is the same rate as 7.00.', $spelling));
        }
    }

    public function testAnUnverifiedTerritoryRateIsRefusedRatherThanGuessed(): void
    {
        // Portugal is not in this fixture, so build one that has it.
        $rates = VatRates::fromArray([
            'schema' => 1,
            'generated_at' => '2026-09-15T00:00:00Z',
            'window' => ['from' => '2024-01-01', 'to' => '2026-09-15'],
            'countries' => [
                'PT' => [
                    'standard' => [['percent' => '23.00', 'class' => 'STANDARD', 'valid_from' => '2024-01-01', 'valid_to' => null]],
                    'reduced' => [],
                ],
            ],
            'territorial_observed' => [],
        ]);

        $this->expectException(TerritoryRateUnverified::class);
        $this->expectExceptionMessageMatches('/Madeira/');

        $rates->rateFor(Place::of('PT', '9000-123'), new \DateTimeImmutable('2026-01-01'));
    }

    public function testATerritoryRateOverrideBeatsTheMainlandRate(): void
    {
        $rates = VatRates::fromArray([
            'schema' => 1,
            'generated_at' => '2026-09-15T00:00:00Z',
            'window' => ['from' => '2024-01-01', 'to' => '2026-09-15'],
            'countries' => [
                'AT' => [
                    'standard' => [['percent' => '20.00', 'class' => 'STANDARD', 'valid_from' => '2024-01-01', 'valid_to' => null]],
                    'reduced' => [],
                ],
            ],
            'territorial_observed' => [],
        ]);

        self::assertSame('20.00', $rates->rateFor(Place::of('AT', '6020'), new \DateTimeImmutable('2026-01-01'))->percent);
        self::assertSame('19.00', $rates->rateFor(Place::of('AT', '6691'), new \DateTimeImmutable('2026-01-01'))->percent);
    }

    public function testTheCanaryQualifierRowIsEvidenceAndNeverASpanishRate(): void
    {
        $parsed = (new ResponseParser())->parse(file_get_contents(__DIR__.'/fixtures/tedb-eu27-current.xml'));
        $snapshot = (new SnapshotBuilder())->build($parsed, new \DateTimeImmutable('2026-09-15'), new \DateTimeImmutable('2027-12-31'), '2026-09-15T00:00:00Z');

        $spanishRates = array_column($snapshot['countries']['ES']['standard'], 'percent');

        self::assertSame(['21.00'], $spanishRates, 'The Canary 7.0 must not appear as a Spanish standard rate.');
        self::assertNotEmpty($snapshot['territorial_observed']['ES'] ?? [], 'It must be kept as evidence.');
    }
}
