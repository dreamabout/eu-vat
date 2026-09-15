<?php

declare(strict_types=1);

namespace Kaikei\EuVat\Territory;

/**
 * A place where the country code alone gives the wrong VAT answer.
 *
 * Three kinds, because the world has three:
 *
 *   - **Outside the VAT area** (`vatArea === false`) — Büsingen, the Canary Islands, Ceuta,
 *     Melilla, Livigno, Åland, Mount Athos, Campione, the French overseas departments. The
 *     Directive does not reach them at all.
 *   - **Inside, at a different rate** (`standardRateOverride`) — Jungholz and Mittelberg at
 *     19% rather than Austria's 20%.
 *   - **Treated as another state** (`treatAs`) — Monaco billed as France, the Isle of Man as
 *     the UK, under Article 7.
 *
 * `rateUnverified` marks a territory known to differ at rates this package has not confirmed
 * from a primary source. Resolution refuses for those, rather than quietly handing back the
 * mainland rate.
 */
final class Territory
{
    /**
     * @param list<string>                                     $aliases
     * @param list<array{match: string, values: list<string>}> $postalRules
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $memberState,
        public readonly ?string $isoCountry,
        public readonly bool $vatArea,
        public readonly ?string $standardRateOverride,
        public readonly ?string $treatAs,
        public readonly bool $rateUnverified,
        public readonly string $legalBasis,
        /** Names TEDB may use for this place, matched as substrings of its comments. */
        public readonly array $aliases,
        public readonly array $postalRules,
        /** 'complete' | 'partial' | 'none' — declared, so a gap cannot hide as an absence. */
        public readonly string $postalCoverage,
        public readonly ?string $verifiedOn,
    ) {
    }

    /** An exact-code hit, which outranks any prefix. */
    public function matchesExactly(string $normalisedPostalCode): bool
    {
        foreach ($this->postalRules as $rule) {
            if ('exact' !== $rule['match']) {
                continue;
            }

            foreach ($rule['values'] as $value) {
                if ($normalisedPostalCode === strtoupper($value)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The length of the longest matching prefix, or null. Length is returned rather than a
     * boolean so the most specific rule wins: 97150 is Saint-Martin, not Guadeloupe, even
     * though Guadeloupe owns the 971 prefix it sits inside.
     */
    public function longestPrefixMatch(string $normalisedPostalCode): ?int
    {
        $longest = null;
        foreach ($this->postalRules as $rule) {
            if ('prefix' !== $rule['match']) {
                continue;
            }

            foreach ($rule['values'] as $value) {
                $candidate = strtoupper($value);
                if (str_starts_with($normalisedPostalCode, $candidate)) {
                    $longest = max($longest ?? 0, \strlen($candidate));
                }
            }
        }

        return $longest;
    }

    public function hasPostalRules(): bool
    {
        return [] !== $this->postalRules;
    }
}
