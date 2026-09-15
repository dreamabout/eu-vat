<?php

declare(strict_types=1);

namespace Kaikei\EuVat\Tests\Territory;

use Kaikei\EuVat\Place;
use Kaikei\EuVat\Territory\TerritoryTable;
use PHPUnit\Framework\TestCase;

/**
 * The curated table, exercised against the entries that actually catch people out.
 *
 * Every assertion here is a place where a country code alone gives the wrong answer: German
 * soil outside the VAT area, Austrian soil at a different rate, a sovereign state billed as
 * France.
 */
final class TerritoryTableTest extends TestCase
{
    private function table(): TerritoryTable
    {
        return TerritoryTable::fromFile(__DIR__.'/../../data/territories.json');
    }

    public function testBusingenIsGermanSoilOutsideTheVatArea(): void
    {
        $territory = $this->table()->resolve(Place::of('DE', '78266'));

        self::assertNotNull($territory);
        self::assertSame('Büsingen am Hochrhein', $territory->name);
        self::assertFalse($territory->vatArea);
        self::assertStringContainsString('Art. 6(2)(b)', $territory->legalBasis);
    }

    public function testMainlandGermanyIsNotATerritory(): void
    {
        self::assertNull($this->table()->resolve(Place::of('DE', '10115')));
    }

    public function testJungholzIsInsideTheVatAreaAtItsOwnRate(): void
    {
        $territory = $this->table()->resolve(Place::of('AT', '6691'));

        self::assertNotNull($territory);
        self::assertTrue($territory->vatArea);
        self::assertSame('19.00', $territory->standardRateOverride);
    }

    public function testMittelbergSharesTheJungholzEntry(): void
    {
        foreach (['6991', '6992', '6993'] as $postalCode) {
            $territory = $this->table()->resolve(Place::of('AT', $postalCode));

            self::assertNotNull($territory, sprintf('AT %s should resolve.', $postalCode));
            self::assertSame('19.00', $territory->standardRateOverride);
        }
    }

    public function testCanaryIslandsCeutaAndMelillaAreAllOutsideTheVatArea(): void
    {
        foreach (['35001' => 'Canary Islands', '38001' => 'Canary Islands', '51001' => 'Ceuta', '52001' => 'Melilla'] as $postalCode => $name) {
            $territory = $this->table()->resolve(Place::of('ES', (string) $postalCode));

            self::assertNotNull($territory, sprintf('ES %s should resolve.', $postalCode));
            self::assertSame($name, $territory->name);
            self::assertFalse($territory->vatArea);
        }

        self::assertNull($this->table()->resolve(Place::of('ES', '28001')), 'Madrid is mainland.');
    }

    public function testMonacoResolvesByCountryCodeWithNoPostalCodeNeeded(): void
    {
        $territory = $this->table()->resolve(Place::countryOnly('MC'));

        self::assertNotNull($territory);
        self::assertSame('FR', $territory->treatAs);
        self::assertTrue($territory->vatArea);
    }

    public function testGreekMountAthosResolvesWhicheverGreekCodeIsUsed(): void
    {
        // Place normalises GR to EL, so a caller holding either code gets the same answer.
        self::assertNotNull($this->table()->resolve(Place::of('GR', '63086')));
        self::assertNotNull($this->table()->resolve(Place::of('EL', '63086')));
    }

    public function testFrenchOverseasDepartmentsAreOutsideTheVatArea(): void
    {
        foreach (['97110' => 'Guadeloupe', '97200' => 'Martinique', '97300' => 'French Guiana', '97400' => 'Réunion', '97600' => 'Mayotte'] as $postalCode => $name) {
            $territory = $this->table()->resolve(Place::of('FR', (string) $postalCode));

            self::assertNotNull($territory, sprintf('FR %s should resolve.', $postalCode));
            self::assertSame($name, $territory->name);
            self::assertFalse($territory->vatArea);
        }
    }

    public function testAPortugueseAutonomousRegionIsFlaggedAsRateUnverified(): void
    {
        $territory = $this->table()->resolve(Place::of('PT', '9000-123'));

        self::assertNotNull($territory);
        self::assertSame('Madeira', $territory->name);
        self::assertTrue($territory->vatArea);
        self::assertTrue($territory->rateUnverified, 'Madeira rates are not verified here; resolution must refuse rather than guess.');
    }

    public function testCountriesWithTerritoriesAreExactlyThoseAPostalCodeCanDistinguish(): void
    {
        $countries = $this->table()->countriesWithPostalTerritories();

        foreach (['DE', 'ES', 'FR', 'IT', 'AT', 'FI', 'EL', 'PT'] as $expected) {
            self::assertContains($expected, $countries);
        }

        // Akrotiri and Dhekelia has no postal rule, so demanding a Cypriot postcode would be
        // pure friction: no postcode could ever change the answer.
        self::assertNotContains('CY', $countries);
    }

    public function testEveryEntryCitesItsLegalBasis(): void
    {
        foreach ($this->table()->all() as $id => $territory) {
            self::assertNotSame('', trim($territory->legalBasis), sprintf('Territory "%s" has no legal basis.', $id));
        }
    }

    public function testPostalMatchingIgnoresSpacingAndCase(): void
    {
        self::assertNotNull($this->table()->resolve(Place::of('IM', 'im1 1aa')));
    }
}
