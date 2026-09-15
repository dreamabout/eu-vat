<?php

declare(strict_types=1);

namespace Kaikei\EuVat\Snapshot;

use Kaikei\EuVat\Rate;
use Kaikei\EuVat\RateClass;
use Kaikei\EuVat\Tedb\IntervalDeriver;
use Kaikei\EuVat\Tedb\ParsedResponse;

/**
 * Builds the snapshot structure that ships with the package.
 *
 * Deterministic by construction: countries sorted, rates sorted within a country, keys in a
 * fixed order. A nightly job that produced a differently-ordered but equivalent file would
 * generate a diff every night, and a diff that appears every night is one nobody reads.
 */
final class SnapshotBuilder
{
    public const SCHEMA = 1;

    public function __construct(private readonly IntervalDeriver $deriver = new IntervalDeriver())
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(ParsedResponse $response, \DateTimeImmutable $windowFrom, \DateTimeImmutable $windowTo, string $generatedAt): array
    {
        $derived = $this->deriver->derive($response);

        $countries = [];
        foreach ($derived as $country => $bands) {
            $countries[$country] = [
                'standard' => array_map($this->encodeRate(...), $bands['standard']),
                'reduced' => array_map($this->encodeRate(...), $bands['reduced']),
            ];
        }

        ksort($countries);

        return [
            'schema' => self::SCHEMA,
            'generated_at' => $generatedAt,
            'window' => [
                'from' => $windowFrom->format('Y-m-d'),
                'to' => $windowTo->format('Y-m-d'),
            ],
            'countries' => $countries,
            'territorial_observed' => $this->encodeQualifiers($response),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function encodeRate(Rate $rate): array
    {
        return [
            'percent' => $rate->percent,
            'class' => $rate->rateClass->value,
            'valid_from' => $rate->validFrom->format('Y-m-d'),
            'valid_to' => $rate->validTo?->format('Y-m-d'),
        ];
    }

    /**
     * Qualifier rows are kept as evidence, never as country rates: they are what the curated
     * territory table is cross-checked against.
     *
     * @return array<string, list<array<string, string>>>
     */
    private function encodeQualifiers(ParsedResponse $response): array
    {
        $observed = [];
        foreach ($response->qualifierSamples() as $sample) {
            $key = $sample->country;
            $entry = [
                'percent' => $sample->percent,
                'class' => $sample->rateClass->value,
                'qualifier' => (string) $sample->qualifier,
            ];
            $observed[$key][$this->qualifierKey($entry)] = $entry;
        }

        $encoded = [];
        foreach ($observed as $country => $entries) {
            ksort($entries);
            $encoded[$country] = array_values($entries);
        }

        ksort($encoded);

        return $encoded;
    }

    /**
     * @param array<string, string> $entry
     */
    private function qualifierKey(array $entry): string
    {
        return $entry['qualifier'].'|'.$entry['class'].'|'.$entry['percent'];
    }

    /**
     * Rebuilds a Rate from its encoded form. Kept beside the encoder so the two cannot drift.
     *
     * @param array<string, mixed> $encoded
     */
    public static function decodeRate(string $country, array $encoded): Rate
    {
        $validTo = $encoded['valid_to'] ?? null;

        return new Rate(
            country: $country,
            rateClass: RateClass::from((string) ($encoded['class'] ?? RateClass::STANDARD->value)),
            percent: (string) ($encoded['percent'] ?? '0.00'),
            validFrom: new \DateTimeImmutable((string) $encoded['valid_from'].' 00:00:00', new \DateTimeZone('UTC')),
            validTo: null === $validTo ? null : new \DateTimeImmutable((string) $validTo.' 00:00:00', new \DateTimeZone('UTC')),
        );
    }
}
