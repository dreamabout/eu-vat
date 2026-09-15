<?php

declare(strict_types=1);

namespace Kaikei\EuVat\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Pins the two environment facts the whole package rests on.
 *
 * bcmath is a hard requirement rather than a convenience: every rate and amount here is a
 * decimal string, and a single float cast would silently reintroduce the rounding errors the
 * decimal-string discipline exists to prevent.
 */
final class EnvironmentTest extends TestCase
{
    public function testBcmathIsAvailable(): void
    {
        self::assertTrue(\extension_loaded('bcmath'), 'ext-bcmath is required; see composer.json.');
    }

    public function testBcmathComparesScaledDecimalsExactly(): void
    {
        // The comparison the rate matching depends on: '7', '7.0' and '7.000' are one rate.
        self::assertSame(0, bccomp('7', '7.000', 6));
        self::assertSame(0, bccomp('25.5', '25.500000', 6));
        self::assertSame(-1, bccomp('19', '19.000001', 6));
    }
}
