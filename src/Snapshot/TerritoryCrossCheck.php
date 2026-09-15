<?php

declare(strict_types=1);

namespace Kaikei\EuVat\Snapshot;

use Kaikei\EuVat\Tedb\ParsedResponse;
use Kaikei\EuVat\Territory\TerritoryTable;

/**
 * Keeps the hand-curated territory table honest against what TEDB actually reports.
 *
 * The table cannot be synced — it encodes law, and the Commission's own summary of that law
 * contradicts the Directive — so it is maintained by hand and will, left alone, quietly rot.
 * This is the alarm on that: TEDB naming a territory the table has never heard of means the
 * world moved, and someone needs to read a statute rather than a diff.
 *
 * Non-territorial qualifiers are allowed through by name. `VAT - Import - ` is a scheme, not a
 * place: Germany's import VAT is the same 19% charged on the same soil.
 */
final class TerritoryCrossCheck
{
    /**
     * Qualifiers that name a scheme rather than a territory, and so need no table entry.
     *
     * @var list<string>
     */
    private const KNOWN_SCHEMES = ['Import'];

    public function __construct(private readonly TerritoryTable $territories)
    {
    }

    /**
     * Qualifiers TEDB reported that are neither a curated territory nor a known scheme.
     *
     * @return list<string> `COUNTRY: Qualifier (rate%)`, sorted, deduplicated
     */
    public function unrecognisedQualifiers(ParsedResponse $response): array
    {
        $unrecognised = [];

        foreach ($response->qualifierSamples() as $sample) {
            $qualifier = (string) $sample->qualifier;

            if ($this->isKnownScheme($qualifier) || $this->territories->hasTerritoryNamed($qualifier)) {
                continue;
            }

            $unrecognised[sprintf('%s: %s (%s%%)', $sample->country, $qualifier, $sample->percent)] = true;
        }

        $list = array_keys($unrecognised);
        sort($list);

        return $list;
    }

    private function isKnownScheme(string $qualifier): bool
    {
        foreach (self::KNOWN_SCHEMES as $scheme) {
            if (0 === strcasecmp(trim($qualifier), $scheme)) {
                return true;
            }
        }

        return false;
    }
}
