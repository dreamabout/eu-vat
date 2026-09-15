<?php

declare(strict_types=1);

namespace Kaikei\EuVat;

/**
 * Where a supply takes place: a country, and — where it changes the answer — a postal code.
 *
 * The postal code is not decoration. Büsingen (78266) is German soil outside the EU VAT area
 * entirely; Jungholz (6691) is Austrian soil charged at 19% rather than 20%. A country code
 * alone cannot distinguish them from the mainland, so this class makes the distinction a caller
 * must confront rather than one it can omit by accident.
 *
 * Hence three constructors rather than one optional argument:
 *
 *   - {@see of()} — country and postal code. The normal case.
 *   - {@see countryOnly()} — country alone. Throws {@see Exception\PostalCodeRequired} on
 *     resolution if that country has territories, because the answer genuinely is not knowable.
 *   - {@see countryOnlyAssumingMainland()} — country alone, with the mainland assumption made
 *     explicitly. Resolves, and the assumption sits at the call site where it can be found.
 *
 * The third exists because assumptions get made regardless; the only question is whether they
 * are greppable afterwards.
 */
final class Place
{
    private function __construct(
        public readonly string $country,
        public readonly ?string $postalCode,
        public readonly bool $mainlandAssumed,
    ) {
    }

    public static function of(string $country, string $postalCode): self
    {
        return new self(self::normaliseCountry($country), self::normalisePostalCode($postalCode), false);
    }

    /** Country alone. Resolution throws if this country has territories. */
    public static function countryOnly(string $country): self
    {
        return new self(self::normaliseCountry($country), null, false);
    }

    /** Country alone, explicitly assuming the mainland. Resolution proceeds. */
    public static function countryOnlyAssumingMainland(string $country): self
    {
        return new self(self::normaliseCountry($country), null, true);
    }

    public function hasPostalCode(): bool
    {
        return null !== $this->postalCode;
    }

    /**
     * Greece is `GR` in ISO-3166 and `EL` for VAT purposes, and TEDB rejects `GR` outright.
     * Translating here means every caller may use whichever it holds.
     */
    private static function normaliseCountry(string $country): string
    {
        $iso = strtoupper(trim($country));

        return 'GR' === $iso ? 'EL' : $iso;
    }

    /** Postal codes are matched without spacing or case, so "BT1 1AA" and "bt11aa" agree. */
    private static function normalisePostalCode(string $postalCode): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $postalCode));
    }
}
