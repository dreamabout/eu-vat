<?php

declare(strict_types=1);

namespace Kaikei\EuVat\Territory;

use Kaikei\EuVat\Exception\TedbFault;
use Kaikei\EuVat\Place;

/**
 * The curated territory table, loaded from `data/territories.json`.
 *
 * Hand-maintained on purpose. The Commission publishes a summary page of territorial status
 * which, read programmatically, contradicts the Directive it summarises — so every entry here
 * cites an article of 2006/112/EC rather than a URL that might be reworded.
 */
final class TerritoryTable
{
    /**
     * @param array<string, Territory> $territories
     */
    private function __construct(private readonly array $territories)
    {
    }

    public static function fromFile(string $path): self
    {
        $raw = @file_get_contents($path);
        if (false === $raw) {
            throw TedbFault::unparseable(sprintf('cannot read the territory table at "%s".', $path));
        }

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);

        return self::fromArray($decoded);
    }

    /**
     * @param array<string, mixed> $entries
     */
    public static function fromArray(array $entries): self
    {
        $territories = [];
        foreach ($entries as $id => $entry) {
            if (str_starts_with($id, '_') || !\is_array($entry)) {
                // `_README` and friends document the file for whoever edits it next.
                continue;
            }

            $territories[$id] = new Territory(
                id: $id,
                name: (string) ($entry['name'] ?? $id),
                memberState: strtoupper((string) ($entry['member_state'] ?? '')),
                isoCountry: isset($entry['iso_country']) ? strtoupper((string) $entry['iso_country']) : null,
                vatArea: (bool) ($entry['vat_area'] ?? true),
                standardRateOverride: isset($entry['rate_override']['standard'])
                    ? (string) $entry['rate_override']['standard']
                    : null,
                treatAs: isset($entry['treat_as']) ? strtoupper((string) $entry['treat_as']) : null,
                rateUnverified: (bool) ($entry['rate_unverified'] ?? false),
                legalBasis: (string) ($entry['legal_basis'] ?? ''),
                postalRules: self::postalRules($entry['postal_codes'] ?? []),
                verifiedOn: isset($entry['verified_on']) ? (string) $entry['verified_on'] : null,
            );
        }

        return new self($territories);
    }

    /**
     * The territory a place falls in, or null for the mainland.
     *
     * A territory that is its own ISO country — Monaco, the Isle of Man — matches on the country
     * code alone, since no postal code could change that answer.
     */
    public function resolve(Place $place): ?Territory
    {
        foreach ($this->territories as $territory) {
            if (null !== $territory->isoCountry && $territory->isoCountry === $place->country) {
                return $territory;
            }
        }

        if (!$place->hasPostalCode()) {
            return null;
        }

        foreach ($this->territories as $territory) {
            if ($territory->memberState !== $place->country) {
                continue;
            }

            if ($territory->matchesPostalCode((string) $place->postalCode)) {
                return $territory;
            }
        }

        return null;
    }

    /**
     * Countries where a postal code can actually change the answer — and therefore the countries
     * for which a country-only lookup must refuse.
     *
     * A territory with no postal rule (the Italian waters of Lake Lugano; Akrotiri and Dhekelia)
     * is excluded deliberately: demanding a postcode that could never disambiguate anything is
     * friction without a payoff.
     *
     * @return list<string>
     */
    public function countriesWithPostalTerritories(): array
    {
        $countries = [];
        foreach ($this->territories as $territory) {
            if ($territory->hasPostalRules() && null === $territory->isoCountry) {
                $countries[$territory->memberState] = true;
            }
        }

        $list = array_keys($countries);
        sort($list);

        return $list;
    }

    /**
     * @return list<string>
     */
    public function territoryNamesIn(string $country): array
    {
        $names = [];
        foreach ($this->territories as $territory) {
            if ($territory->memberState === $country && $territory->hasPostalRules() && null === $territory->isoCountry) {
                $names[] = $territory->name;
            }
        }

        sort($names);

        return $names;
    }

    /**
     * Whether any curated entry's name matches a qualifier TEDB reported. This is the
     * cross-check that keeps a hand-maintained table honest: TEDB naming a territory we have
     * never heard of means the world moved, not that the code is broken.
     */
    public function hasTerritoryNamed(string $qualifier): bool
    {
        $needle = $this->normaliseName($qualifier);

        foreach ($this->territories as $territory) {
            if ($this->normaliseName($territory->name) === $needle) {
                return true;
            }

            // "Jungholz, Mittelberg" names one entry covering both.
            foreach (preg_split('/\s*,\s*/', $territory->name) ?: [] as $part) {
                if ('' !== $part && $this->normaliseName($part) === $needle) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return array<string, Territory>
     */
    public function all(): array
    {
        return $this->territories;
    }

    private function normaliseName(string $name): string
    {
        $folded = iconv('UTF-8', 'ASCII//TRANSLIT', $name);

        return strtolower(trim((string) preg_replace('/[^A-Za-z0-9]+/', ' ', false === $folded ? $name : $folded)));
    }

    /**
     * @return list<array{match: string, values: list<string>}>
     */
    private static function postalRules(mixed $rules): array
    {
        if (!\is_array($rules)) {
            return [];
        }

        $parsed = [];
        foreach ($rules as $rule) {
            if (!\is_array($rule) || !isset($rule['values']) || !\is_array($rule['values'])) {
                continue;
            }

            $parsed[] = [
                'match' => 'prefix' === ($rule['match'] ?? 'exact') ? 'prefix' : 'exact',
                'values' => array_values(array_map(static fn (mixed $v): string => strtoupper(trim((string) $v)), $rule['values'])),
            ];
        }

        return $parsed;
    }
}
