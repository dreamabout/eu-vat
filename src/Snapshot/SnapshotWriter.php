<?php

declare(strict_types=1);

namespace Kaikei\EuVat\Snapshot;

/**
 * Serialises a snapshot deterministically, and hashes the part of it that actually matters.
 *
 * The hash deliberately covers `countries` and `territorial_observed` only. `generated_at`
 * changes on every run, so hashing the whole document would report a change every night, the
 * job would commit every night, and the signal the whole design rests on — a commit means a
 * rate moved — would be worth nothing.
 */
final class SnapshotWriter
{
    private const FLAGS = \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE;

    /**
     * @param array<string, mixed> $snapshot
     */
    public function encode(array $snapshot): string
    {
        return json_encode($snapshot, self::FLAGS | \JSON_THROW_ON_ERROR)."\n";
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    public function contentHash(array $snapshot): string
    {
        return hash('sha256', json_encode([
            'countries' => $snapshot['countries'] ?? [],
            'territorial_observed' => $snapshot['territorial_observed'] ?? [],
        ], \JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     */
    public function ratesDiffer(array $a, array $b): bool
    {
        return $this->contentHash($a) !== $this->contentHash($b);
    }
}
