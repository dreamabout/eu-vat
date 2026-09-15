<?php

declare(strict_types=1);

namespace Kaikei\EuVat;

use Kaikei\EuVat\Exception\OutsideVatArea;
use Kaikei\EuVat\Exception\PostalCodeRequired;
use Kaikei\EuVat\Exception\TerritoryRateUnverified;
use Kaikei\EuVat\Exception\UnknownCountry;
use Kaikei\EuVat\Exception\UnknownRateForDate;
use Kaikei\EuVat\Snapshot\SnapshotBuilder;
use Kaikei\EuVat\Territory\TerritoryTable;

/**
 * Point-in-time EU VAT rate lookup over the bundled snapshot.
 *
 * Pure after construction: no network, no database, no clock. Everything it answers comes from
 * two committed files, which is what makes it safe to call from a checkout path and cheap to
 * test exhaustively.
 *
 * The lookup refuses in three situations rather than returning a plausible number:
 *
 *   - a date before the snapshot's window, because extrapolating backwards would silently
 *     misstate a period the data never covered;
 *   - a country-only lookup for a country containing territories, because the answer genuinely
 *     depends on a postal code the caller did not supply;
 *   - a territory outside the VAT area, because "no VAT applies" and "0% VAT applies" are
 *     different facts that book differently.
 */
final class VatRates
{
    private const DEFAULT_RATES = __DIR__.'/../data/rates.json';
    private const DEFAULT_TERRITORIES = __DIR__.'/../data/territories.json';

    /**
     * @param array<string, mixed> $snapshot
     */
    private function __construct(
        private readonly array $snapshot,
        private readonly TerritoryTable $territories,
    ) {
    }

    public static function fromSnapshot(?string $ratesPath = null, ?string $territoriesPath = null): self
    {
        $path = $ratesPath ?? self::DEFAULT_RATES;

        $raw = @file_get_contents($path);
        if (false === $raw) {
            throw new \RuntimeException(sprintf('Cannot read the rate snapshot at "%s". Run bin/regenerate-rates to create it.', $path));
        }

        /** @var array<string, mixed> $snapshot */
        $snapshot = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);

        return new self($snapshot, TerritoryTable::fromFile($territoriesPath ?? self::DEFAULT_TERRITORIES));
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    public static function fromArray(array $snapshot, ?TerritoryTable $territories = null): self
    {
        return new self($snapshot, $territories ?? TerritoryTable::fromFile(self::DEFAULT_TERRITORIES));
    }

    /** The country's own standard rate on a date, ignoring territories entirely. */
    public function standardRate(string $country, \DateTimeImmutable $on): Rate
    {
        $iso = $this->normalise($country);
        $this->guardWindow($iso, $on);

        foreach ($this->encodedRates($iso, 'standard') as $encoded) {
            $rate = SnapshotBuilder::decodeRate($iso, $encoded);
            if ($rate->coversDate($on)) {
                return $rate;
            }
        }

        throw UnknownRateForDate::noRate($iso, $on);
    }

    /** Every rate the country had in force on a date: standard, plus the reduced bands. */
    public function ratesOn(string $country, \DateTimeImmutable $on): RateSet
    {
        $iso = $this->normalise($country);
        $standard = $this->standardRate($iso, $on);

        $reduced = [];
        foreach ($this->encodedRates($iso, 'reduced') as $encoded) {
            $rate = SnapshotBuilder::decodeRate($iso, $encoded);
            if ($rate->coversDate($on)) {
                $reduced[] = $rate;
            }
        }

        return new RateSet($iso, $standard, $reduced);
    }

    /**
     * The standard rate for a place, honouring territories.
     *
     * This is the method a caller deciding what to charge should use. {@see standardRate()}
     * answers a narrower question and will happily return Spain's 21% for a Canary Islands
     * address.
     */
    public function rateFor(Place $place, \DateTimeImmutable $on): Rate
    {
        $territory = $this->territories->resolve($place);

        if (null === $territory) {
            $this->guardPostalCodeRequired($place);

            return $this->standardRate($place->country, $on);
        }

        if (!$territory->vatArea) {
            throw OutsideVatArea::territory($territory->name, $place->country, $place->postalCode, $territory->legalBasis);
        }

        if ($territory->rateUnverified) {
            throw TerritoryRateUnverified::forTerritory($territory->name, $territory->memberState);
        }

        $country = $territory->treatAs ?? $territory->memberState;
        $mainland = $this->standardRate($country, $on);

        if (null === $territory->standardRateOverride) {
            return $mainland;
        }

        // The override borrows the mainland rate's interval: the territory's divergence is a
        // standing rule, so it changes when the country's own rate does, not independently.
        return new Rate(
            country: $country,
            rateClass: RateClass::STANDARD,
            percent: $territory->standardRateOverride,
            validFrom: $mainland->validFrom,
            validTo: $mainland->validTo,
        );
    }

    /** @return list<string> */
    public function countries(): array
    {
        /** @var array<string, mixed> $countries */
        $countries = $this->snapshot['countries'] ?? [];
        $list = array_keys($countries);
        sort($list);

        return $list;
    }

    public function knows(string $country): bool
    {
        return isset($this->snapshot['countries'][$this->normalise($country)]);
    }

    public function generatedAt(): \DateTimeImmutable
    {
        return new \DateTimeImmutable((string) ($this->snapshot['generated_at'] ?? '@0'));
    }

    public function windowStart(): \DateTimeImmutable
    {
        return new \DateTimeImmutable((string) ($this->snapshot['window']['from'] ?? '0001-01-01').' 00:00:00', new \DateTimeZone('UTC'));
    }

    public function territoryTable(): TerritoryTable
    {
        return $this->territories;
    }

    private function guardPostalCodeRequired(Place $place): void
    {
        if ($place->hasPostalCode() || $place->mainlandAssumed) {
            return;
        }

        if (!\in_array($place->country, $this->territories->countriesWithPostalTerritories(), true)) {
            return;
        }

        throw PostalCodeRequired::forCountry($place->country, $this->territories->territoryNamesIn($place->country));
    }

    private function guardWindow(string $country, \DateTimeImmutable $on): void
    {
        if (!$this->knows($country)) {
            throw UnknownCountry::notInSnapshot($country);
        }

        $windowStart = $this->windowStart();
        if ($on->format('Y-m-d') < $windowStart->format('Y-m-d')) {
            throw UnknownRateForDate::beforeWindow($country, $on, $windowStart);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function encodedRates(string $country, string $band): array
    {
        /** @var list<array<string, mixed>> $rates */
        $rates = $this->snapshot['countries'][$country][$band] ?? [];

        return $rates;
    }

    private function normalise(string $country): string
    {
        $iso = strtoupper(trim($country));

        return 'GR' === $iso ? 'EL' : $iso;
    }
}
