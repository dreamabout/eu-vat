<?php

declare(strict_types=1);

namespace Kaikei\EuVat\Tedb;

use Kaikei\EuVat\RateClass;

/**
 * One rate as TEDB reported it at one point in time.
 *
 * A sample is not yet a {@see \Kaikei\EuVat\Rate}: it has a date but no validity interval,
 * because TEDB reports change-points rather than intervals. Turning a run of samples into
 * intervals is {@see IntervalDeriver}'s job.
 */
final class RateSample
{
    public function __construct(
        public readonly string $country,
        public readonly RateClass $rateClass,
        /** Percent to two decimal places, e.g. '25.50'. */
        public readonly string $percent,
        public readonly \DateTimeImmutable $date,
        /**
         * The qualifier naming a territory or special scheme ('Canary Islands', 'Import'),
         * or null for an ordinary country rate.
         */
        public readonly ?string $qualifier,
        /**
         * The raw comment, kept because the shape rule cannot classify everything. TEDB writes
         * Spain's Canary row as "VAT - Canary Islands - " but Austria's Jungholz row as a bare
         * "Jungholz, Mittelberg", which is indistinguishable in shape from a category note like
         * "Import only". Only the curated territory table can tell those apart.
         */
        public readonly ?string $comment = null,
    ) {
    }

    /** The same sample, reclassified as naming a territory. */
    public function asQualified(string $qualifier): self
    {
        return new self($this->country, $this->rateClass, $this->percent, $this->date, $qualifier, $this->comment);
    }

    public function isQualified(): bool
    {
        return null !== $this->qualifier;
    }
}
