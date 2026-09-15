<?php

declare(strict_types=1);

namespace Kaikei\EuVat\Tests\Tedb;

use Kaikei\EuVat\RateClass;
use Kaikei\EuVat\Tedb\CalendarDate;
use Kaikei\EuVat\Tedb\IntervalDeriver;
use Kaikei\EuVat\Tedb\ParsedResponse;
use Kaikei\EuVat\Tedb\RateSample;
use Kaikei\EuVat\Tedb\ResponseParser;
use PHPUnit\Framework\TestCase;

/**
 * TEDB reports the situation at a series of points — semester boundaries, plus the real dates
 * on which something changed. Turning that into "this rate applied from X until Y" is this
 * class's whole job, and two things about it are easy to get wrong.
 *
 * First, the boundary. Intervals are half-open, `[from, to)`. Finland changed from 24% to 25.5%
 * on 2024-09-01, and an order placed that day pays 25.5%. An inclusive upper bound would make
 * both rates valid on the boundary date and the answer would depend on iteration order.
 *
 * Second, the beginning. The oldest interval starts at the window's start, which is only "at or
 * before" the true start — TEDB was never asked about earlier dates. That is recorded rather
 * than papered over, so a lookup before the window can refuse instead of guessing.
 */
final class IntervalDeriverTest extends TestCase
{
    public function testCollapsesConsecutiveEqualSamplesIntoOneInterval(): void
    {
        $derived = (new IntervalDeriver())->derive(new ParsedResponse([
            $this->standard('FI', '24.0', '2024-01-01'),
            $this->standard('FI', '24.0', '2024-07-01'),
            $this->standard('FI', '25.5', '2024-09-01'),
            $this->standard('FI', '25.5', '2025-01-01'),
        ]));

        $standard = $derived['FI']['standard'];

        self::assertCount(2, $standard, 'Four samples, two distinct rates, two intervals.');
        self::assertSame('24.00', $standard[0]->percent);
        self::assertSame('2024-01-01', $standard[0]->validFrom->format('Y-m-d'));
        self::assertSame('2024-09-01', $standard[0]->validTo?->format('Y-m-d'));
    }

    public function testTheLatestIntervalIsOpenEnded(): void
    {
        $derived = (new IntervalDeriver())->derive(new ParsedResponse([
            $this->standard('FI', '24.0', '2024-01-01'),
            $this->standard('FI', '25.5', '2024-09-01'),
        ]));

        $standard = $derived['FI']['standard'];

        self::assertNull($standard[1]->validTo, 'The rate still in force has no end date.');
    }

    /**
     * The boundary itself. This is the assertion that stops an order placed on the change date
     * from being validated against last month's rate.
     */
    public function testTheChangeDateBelongsToTheNewRateNotTheOld(): void
    {
        $derived = (new IntervalDeriver())->derive(new ParsedResponse([
            $this->standard('FI', '24.0', '2024-01-01'),
            $this->standard('FI', '25.5', '2024-09-01'),
        ]));

        [$old, $new] = $derived['FI']['standard'];

        $boundary = CalendarDate::parse('2024-09-01');

        self::assertFalse($old->coversDate($boundary), 'The old rate must not cover the change date.');
        self::assertTrue($new->coversDate($boundary), 'The new rate must cover the change date.');

        $dayBefore = CalendarDate::parse('2024-08-31');
        self::assertTrue($old->coversDate($dayBefore));
        self::assertFalse($new->coversDate($dayBefore));
    }

    public function testARateThatReturnsToAnEarlierValueGetsTwoSeparateIntervals(): void
    {
        // Ireland really did this: 23% -> 21% for the pandemic -> back to 23%.
        $derived = (new IntervalDeriver())->derive(new ParsedResponse([
            $this->standard('IE', '23.0', '2020-01-01'),
            $this->standard('IE', '21.0', '2020-09-01'),
            $this->standard('IE', '23.0', '2021-03-01'),
        ]));

        $standard = $derived['IE']['standard'];

        self::assertCount(3, $standard, 'Returning to an earlier value is a new interval, not a merge.');
        self::assertSame('23.00', $standard[0]->percent);
        self::assertSame('21.00', $standard[1]->percent);
        self::assertSame('23.00', $standard[2]->percent);
        self::assertSame('2021-03-01', $standard[2]->validFrom->format('Y-m-d'));
    }

    public function testTracksEachReducedRateSeparatelyIncludingOnesThatCease(): void
    {
        $derived = (new IntervalDeriver())->derive(new ParsedResponse([
            $this->standard('XX', '20.0', '2024-01-01'),
            $this->reduced('XX', '10.0', '2024-01-01'),
            $this->reduced('XX', '5.0', '2024-01-01'),
            $this->standard('XX', '20.0', '2024-07-01'),
            $this->reduced('XX', '10.0', '2024-07-01'),
            // 5.0 is gone from 2024-07-01 onwards.
        ]));

        $reduced = $derived['XX']['reduced'];

        $byPercent = [];
        foreach ($reduced as $rate) {
            $byPercent[$rate->percent] = $rate;
        }

        self::assertArrayHasKey('10.00', $byPercent);
        self::assertArrayHasKey('5.00', $byPercent);
        self::assertNull($byPercent['10.00']->validTo, 'Still in force.');
        self::assertSame('2024-07-01', $byPercent['5.00']->validTo?->format('Y-m-d'), 'Ceased when it stopped being reported.');
    }

    public function testQualifierSamplesNeverBecomeCountryRates(): void
    {
        $derived = (new IntervalDeriver())->derive(new ParsedResponse([
            $this->standard('ES', '21.0', '2024-01-01'),
            new RateSample('ES', RateClass::STANDARD, '7.00', CalendarDate::parse('2024-01-01'), 'Canary Islands'),
        ]));

        $standard = $derived['ES']['standard'];

        self::assertCount(1, $standard);
        self::assertSame('21.00', $standard[0]->percent);
    }

    public function testDerivesTheRealFinnishChangeFromTheFixture(): void
    {
        $parsed = (new ResponseParser())->parse(file_get_contents(__DIR__.'/../fixtures/tedb-dk-de-fi-2024-2026.xml'));
        $derived = (new IntervalDeriver())->derive($parsed);

        $standard = $derived['FI']['standard'];

        self::assertSame('24.00', $standard[0]->percent);
        self::assertSame('25.50', $standard[1]->percent);
        self::assertSame('2024-09-01', $standard[1]->validFrom->format('Y-m-d'));
        self::assertNull($standard[1]->validTo);
    }

    public function testDenmarkHasASingleUnchangedIntervalAcrossTheWholeWindow(): void
    {
        $parsed = (new ResponseParser())->parse(file_get_contents(__DIR__.'/../fixtures/tedb-dk-de-fi-2024-2026.xml'));
        $derived = (new IntervalDeriver())->derive($parsed);

        $standard = $derived['DK']['standard'];

        self::assertCount(1, $standard, 'DK never changed in the window; six samples, one interval.');
        self::assertSame('25.00', $standard[0]->percent);
        self::assertNull($standard[0]->validTo);
    }

    private function standard(string $country, string $percent, string $date): RateSample
    {
        return new RateSample($country, RateClass::STANDARD, bcadd($percent, '0', 2), CalendarDate::parse($date), null);
    }

    private function reduced(string $country, string $percent, string $date): RateSample
    {
        return new RateSample($country, RateClass::REDUCED, bcadd($percent, '0', 2), CalendarDate::parse($date), null);
    }
}
