<?php

declare(strict_types=1);

namespace Kaikei\EuVat;

/**
 * The kind of rate a {@see Rate} represents.
 *
 * TEDB's own vocabulary is wider than this: alongside the four real rate bands it emits
 * `NOT_APPLICABLE`, `OUT_OF_SCOPE` and `EXEMPTED` rows. Those are category-specific
 * exemptions — "donations of food listed in Annex 1", "the Austrian section of cross-border
 * rail transport" — not rates a country charges, and carrying them would invite a caller to
 * treat one as an answer to "what rate applies here". They are dropped during parsing.
 */
enum RateClass: string
{
    case STANDARD = 'STANDARD';
    case REDUCED = 'REDUCED';
    case SUPER_REDUCED = 'SUPER_REDUCED';
    case PARKING = 'PARKING';

    /**
     * Maps TEDB's `rate.type` vocabulary onto ours. Returns null for the non-rate rows
     * described above, which the caller is expected to skip.
     */
    public static function fromTedb(string $tedbRateType): ?self
    {
        return match (strtoupper(trim($tedbRateType))) {
            'DEFAULT' => self::STANDARD,
            'REDUCED_RATE' => self::REDUCED,
            'SUPER_REDUCED_RATE' => self::SUPER_REDUCED,
            'PARKING_RATE' => self::PARKING,
            default => null,
        };
    }
}
