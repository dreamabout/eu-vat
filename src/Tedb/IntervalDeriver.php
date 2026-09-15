<?php

declare(strict_types=1);

namespace Kaikei\EuVat\Tedb;

use Kaikei\EuVat\Rate;
use Kaikei\EuVat\RateClass;

/**
 * Turns TEDB's point-in-time samples into validity intervals.
 *
 * TEDB answers "what was the rate on this date", sampled at each semester boundary in the
 * requested range plus any date on which something actually changed. What a consumer needs is
 * the inverse: "between which dates did this rate apply". Deriving one from the other is a run
 * of consecutive equal samples collapsing into a single interval.
 *
 * Two properties are deliberate:
 *
 *   - **Intervals are half-open, `[validFrom, validTo)`.** The date a rate changes belongs to
 *     the new rate. Anything else makes the boundary date ambiguous.
 *   - **A rate returning to an earlier value starts a new interval**, rather than being merged
 *     with the older one. Ireland's standard rate went 23% → 21% → 23%; those are three facts
 *     about three periods, not two.
 *
 * Reduced rates need a slightly different treatment from the standard rate, because a country
 * has exactly one standard rate but any number of reduced ones at the same moment. So a reduced
 * rate's interval is the run of sample dates on which it was *present*, and it ends when TEDB
 * stops reporting it.
 */
final class IntervalDeriver
{
    /**
     * @return array<string, array{standard: list<Rate>, reduced: list<Rate>}>
     */
    public function derive(ParsedResponse $response): array
    {
        $byCountry = [];
        foreach ($response->all() as $sample) {
            if ($sample->isQualified()) {
                // Territorial and special-scheme rows are evidence, never country rates.
                continue;
            }
            $byCountry[$sample->country][] = $sample;
        }

        ksort($byCountry);

        $derived = [];
        foreach ($byCountry as $country => $samples) {
            $grid = $this->dateGrid($samples);

            $derived[$country] = [
                'standard' => $this->deriveStandard($country, $samples, $grid),
                'reduced' => $this->deriveReduced($country, $samples, $grid),
            ];
        }

        return $derived;
    }

    /**
     * Every distinct date TEDB reported for this country, ascending. Absence from a grid date is
     * meaningful — it is how a ceased reduced rate is recognised — so the grid must come from
     * all of the country's samples, not from the band being derived.
     *
     * @param list<RateSample> $samples
     *
     * @return list<string>
     */
    private function dateGrid(array $samples): array
    {
        $dates = [];
        foreach ($samples as $sample) {
            $dates[$sample->date->format('Y-m-d')] = true;
        }

        $grid = array_keys($dates);
        sort($grid);

        return $grid;
    }

    /**
     * @param list<RateSample> $samples
     * @param list<string>     $grid
     *
     * @return list<Rate>
     */
    private function deriveStandard(string $country, array $samples, array $grid): array
    {
        $valueByDate = [];
        foreach ($samples as $sample) {
            if (RateClass::STANDARD === $sample->rateClass) {
                $valueByDate[$sample->date->format('Y-m-d')] = $sample->percent;
            }
        }

        $runs = $this->runsOf($grid, static fn (string $date): ?string => $valueByDate[$date] ?? null);

        $rates = [];
        foreach ($runs as $run) {
            $rates[] = new Rate(
                country: $country,
                rateClass: RateClass::STANDARD,
                percent: $run['value'],
                validFrom: CalendarDate::parse($run['from']),
                validTo: null === $run['to'] ? null : CalendarDate::parse($run['to']),
            );
        }

        return $rates;
    }

    /**
     * @param list<RateSample> $samples
     * @param list<string>     $grid
     *
     * @return list<Rate>
     */
    private function deriveReduced(string $country, array $samples, array $grid): array
    {
        /** @var array<string, array{class: RateClass, percent: string, dates: array<string, true>}> $bands */
        $bands = [];
        foreach ($samples as $sample) {
            if (RateClass::STANDARD === $sample->rateClass) {
                continue;
            }
            $key = $sample->rateClass->value.'|'.$sample->percent;
            $bands[$key] ??= ['class' => $sample->rateClass, 'percent' => $sample->percent, 'dates' => []];
            $bands[$key]['dates'][$sample->date->format('Y-m-d')] = true;
        }

        ksort($bands);

        $rates = [];
        foreach ($bands as $band) {
            // "Present" is the value here, so a gap in the grid ends the interval.
            $runs = $this->runsOf($grid, static fn (string $date): ?string => isset($band['dates'][$date]) ? $band['percent'] : null);

            foreach ($runs as $run) {
                $rates[] = new Rate(
                    country: $country,
                    rateClass: $band['class'],
                    percent: $band['percent'],
                    validFrom: CalendarDate::parse($run['from']),
                    validTo: null === $run['to'] ? null : CalendarDate::parse($run['to']),
                );
            }
        }

        return $rates;
    }

    /**
     * Walks the date grid and collapses consecutive equal values into runs.
     *
     * A run ends when the value changes OR becomes absent; in both cases the run's exclusive
     * upper bound is the grid date at which that happened. A run still live at the end of the
     * grid is open-ended, because TEDB reporting a rate at the newest date it knows about means
     * the rate is still in force, not that it expires there.
     *
     * @param list<string>              $grid
     * @param callable(string): ?string $valueAt
     *
     * @return list<array{value: string, from: string, to: ?string}>
     */
    private function runsOf(array $grid, callable $valueAt): array
    {
        $runs = [];
        $current = null;

        foreach ($grid as $date) {
            $value = $valueAt($date);

            if (null === $current) {
                if (null !== $value) {
                    $current = ['value' => $value, 'from' => $date, 'to' => null];
                }

                continue;
            }

            if ($value === $current['value']) {
                continue;
            }

            $current['to'] = $date;
            $runs[] = $current;

            $current = null === $value ? null : ['value' => $value, 'from' => $date, 'to' => null];
        }

        if (null !== $current) {
            $runs[] = $current;
        }

        return $runs;
    }
}
