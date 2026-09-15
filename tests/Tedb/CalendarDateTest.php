<?php

declare(strict_types=1);

namespace Kaikei\EuVat\Tests\Tedb;

use Kaikei\EuVat\Tedb\CalendarDate;
use PHPUnit\Framework\TestCase;

/**
 * TEDB stamps every row with an `xs:date` that carries a timezone offset — `2024-09-01+02:00`.
 *
 * Read as an instant, that is midnight in Helsinki, which is 2024-08-31T22:00Z. Normalise it to
 * UTC and Finland's rate change lands a day early: every order placed on 1 September would be
 * validated against the old rate, and nothing else in the system would look wrong. The offset
 * describes the timezone of the jurisdiction publishing the date, not a moment in time.
 *
 * So these dates are calendar dates. The offset is discarded, deliberately.
 */
final class CalendarDateTest extends TestCase
{
    public function testDiscardsTheTimezoneOffsetRatherThanConvertingThroughIt(): void
    {
        $date = CalendarDate::parse('2024-09-01+02:00');

        self::assertSame('2024-09-01', $date->format('Y-m-d'));
    }

    public function testDoesNotShiftBackwardsForAPositiveOffset(): void
    {
        // The regression this class exists for: converting to UTC would give 2024-08-31.
        self::assertNotSame('2024-08-31', CalendarDate::parse('2024-09-01+02:00')->format('Y-m-d'));
    }

    public function testHandlesAWinterOffsetToo(): void
    {
        // TEDB emits +01:00 for dates in CET and +02:00 in CEST, in the same response.
        self::assertSame('2026-01-01', CalendarDate::parse('2026-01-01+01:00')->format('Y-m-d'));
    }

    public function testHandlesANegativeOffset(): void
    {
        self::assertSame('2024-09-01', CalendarDate::parse('2024-09-01-05:00')->format('Y-m-d'));
    }

    public function testHandlesZuluAndBareDates(): void
    {
        self::assertSame('2024-09-01', CalendarDate::parse('2024-09-01Z')->format('Y-m-d'));
        self::assertSame('2024-09-01', CalendarDate::parse('2024-09-01')->format('Y-m-d'));
    }

    public function testIsAlwaysMidnightUtcSoComparisonsAreStable(): void
    {
        $date = CalendarDate::parse('2024-09-01+02:00');

        self::assertSame('2024-09-01T00:00:00+00:00', $date->format('c'));
    }

    public function testRejectsSomethingThatIsNotADate(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        CalendarDate::parse('the first of September');
    }
}
