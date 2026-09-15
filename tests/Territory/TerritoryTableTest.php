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

    public function testAPortugueseAutonomousRegionCarriesItsOwnStandardRate(): void
    {
        $madeira = $this->table()->resolve(Place::of('PT', '9000-123'));

        self::assertNotNull($madeira);
        self::assertSame('Madeira', $madeira->name);
        self::assertTrue($madeira->vatArea);
        self::assertSame('22.00', $madeira->standardRateOverride, 'Madeira charges 22%, not mainland 23%.');
        self::assertFalse($madeira->rateUnverified);

        $azores = $this->table()->resolve(Place::of('PT', '9500-321'));

        self::assertNotNull($azores);
        self::assertSame('Azores', $azores->name);
        self::assertSame('16.00', $azores->standardRateOverride);
    }

    public function testMainlandPortugalIsNotATerritory(): void
    {
        self::assertNull($this->table()->resolve(Place::of('PT', '1000-001')), 'Lisbon is mainland.');
    }

    /**
     * Saint-Martin and Saint-Barthélemy were detached from Guadeloupe in 2007 but kept their
     * 971xx postal codes, so both sit INSIDE the prefix Guadeloupe owns. Resolving in file order
     * attributes them to Guadeloupe; exact codes have to outrank prefixes.
     */
    public function testAnExactCodeOutranksAPrefixItSitsInside(): void
    {
        $table = $this->table();

        self::assertSame('Saint-Martin', $table->resolve(Place::of('FR', '97150'))?->name);
        self::assertSame('Saint-Barthélemy', $table->resolve(Place::of('FR', '97133'))?->name);
        self::assertSame('Guadeloupe', $table->resolve(Place::of('FR', '97110'))?->name);
    }

    public function testFrenchOverseasCollectivitiesOutsideTheEuAreAlsoOutsideTheVatArea(): void
    {
        // OCTs rather than Art. 6 carve-outs: they left the EU, so the Directive never reaches
        // them. Different reason, same answer, and an order can still be addressed to one.
        foreach (['97500' => 'Saint-Pierre-et-Miquelon', '98800' => 'New Caledonia', '98700' => 'French Polynesia', '98600' => 'Wallis and Futuna'] as $postalCode => $name) {
            $territory = $this->table()->resolve(Place::of('FR', (string) $postalCode));

            self::assertNotNull($territory, sprintf('FR %s should resolve.', $postalCode));
            self::assertSame($name, $territory->name);
            self::assertFalse($territory->vatArea);
        }
    }

    public function testAlandMatchesWithOrWithoutTheCountryPrefix(): void
    {
        // Finland asks that AX precede the code, so an address may arrive either way.
        foreach (['22100', 'AX-22100', 'AX 22100'] as $postalCode) {
            self::assertSame(
                'Åland Islands',
                $this->table()->resolve(Place::of('FI', $postalCode))?->name,
                sprintf('"%s" should resolve to Åland.', $postalCode),
            );
        }

        self::assertNull($this->table()->resolve(Place::of('FI', '00100')), 'Helsinki is mainland.');
    }

    public function testNorthernIrelandIsRecognisedRatherThanPassingAsOrdinaryGb(): void
    {
        $territory = $this->table()->resolve(Place::of('GB', 'BT1 1AA'));

        self::assertNotNull($territory);
        self::assertSame('Northern Ireland', $territory->name);
        self::assertStringContainsString('Windsor Framework', $territory->legalBasis);
    }

    /**
     * Every entry must DECLARE its postal coverage, and an entry claiming coverage must say when
     * it was checked. The Aegean islands are the case this exists for: they qualify by island and
     * population rather than by postcode, so the table honestly declares "none" instead of
     * implying completeness by saying nothing.
     */
    public function testEveryEntryDeclaresItsPostalCoverageAndBacksItUp(): void
    {
        foreach ($this->table()->all() as $id => $territory) {
            self::assertContains(
                $territory->postalCoverage,
                ['complete', 'partial', 'none'],
                sprintf('Territory "%s" does not declare its postal coverage.', $id),
            );

            if ('none' !== $territory->postalCoverage) {
                self::assertNotNull(
                    $territory->verifiedOn,
                    sprintf('Territory "%s" claims postal coverage but was never verified.', $id),
                );
            }
        }
    }

    public function testTheOnlyUncoveredEntriesAreTheOnesWithoutAPostcodeRule(): void
    {
        $uncovered = [];
        foreach ($this->table()->all() as $id => $territory) {
            if ('none' === $territory->postalCoverage) {
                $uncovered[] = $id;
            }
        }

        sort($uncovered);

        // If this list grows, something was added without a postal rule and will never resolve.
        self::assertSame(
            ['aegean_islands', 'akrotiri_and_dhekelia', 'lake_lugano_italian_waters'],
            $uncovered,
        );
    }

    public function testPostalMatchingIgnoresSpacingAndCase(): void
    {
        self::assertNotNull($this->table()->resolve(Place::of('IM', 'im1 1aa')));
    }
}
