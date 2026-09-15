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
        public readonly array $postalRules,
        public readonly ?string $verifiedOn,
    ) {
    }

    public function matchesPostalCode(string $normalisedPostalCode): bool
    {
        foreach ($this->postalRules as $rule) {
            foreach ($rule['values'] as $value) {
                $candidate = strtoupper($value);

                if ('exact' === $rule['match'] && $normalisedPostalCode === $candidate) {
                    return true;
                }

                if ('prefix' === $rule['match'] && str_starts_with($normalisedPostalCode, $candidate)) {
                    return true;
                }
            }
        }

        return false;
    }

    public function hasPostalRules(): bool
    {
        return [] !== $this->postalRules;
    }
}
