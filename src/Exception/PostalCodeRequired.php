<?php

declare(strict_types=1);

namespace Kaikei\EuVat\Exception;

/**
 * A country-only lookup was made for a country whose answer depends on the postal code.
 *
 * The alternative — quietly returning the mainland rate — is wrong for Büsingen, Heligoland,
 * the Canary Islands, Ceuta, Melilla, Livigno, Campione, Åland, Mount Athos, the French overseas
 * departments, Jungholz and Mittelberg, and it is wrong invisibly. A caller that truly has no
 * postal code can say so with `Place::countryOnlyAssumingMainland()`, which resolves and leaves
 * the assumption written down at the call site.
 */
final class PostalCodeRequired extends \RuntimeException implements EuVatException
{
    /**
     * @param list<string> $territories
     */
    public static function forCountry(string $country, array $territories): self
    {
        return new self(sprintf(
            'A postal code is required to resolve a VAT rate for %s: it contains %s (%s), '
            .'where the answer differs from the mainland. '
            .'Supply one with Place::of("%s", $postalCode), or state the assumption explicitly '
            .'with Place::countryOnlyAssumingMainland("%s").',
            $country,
            1 === \count($territories) ? 'the territory' : 'the territories',
            implode(', ', $territories),
            $country,
            $country,
        ));
    }
}
