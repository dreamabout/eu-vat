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
    ) {
    }

    public function isQualified(): bool
    {
        return null !== $this->qualifier;
    }
}
