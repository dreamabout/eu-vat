<?php

declare(strict_types=1);

namespace Kaikei\EuVat\Tests;

use Kaikei\EuVat\Rate;
use Kaikei\EuVat\RateClass;
use Kaikei\EuVat\VatCalculator;
use PHPUnit\Framework\TestCase;

final class VatCalculatorTest extends TestCase
{
    private function rate(string $percent): Rate
    {
        return new Rate('DK', RateClass::STANDARD, $percent, new \DateTimeImmutable('2020-01-01'), null);
    }

    public function testSplitsVatOutOfAGrossAmount(): void
    {
        self::assertSame('250.00', (new VatCalculator())->vatFromGross('1250.00', $this->rate('25.00')));
    }

    /**
     * The distinction the class exists for. 1250.00 CONTAINS 250.00 of VAT at 25%; adding 25%
     * to 1250.00 would be 312.50. Both are defensible-looking numbers and only one is right.
     */
    public function testGrossInclusiveAndNetExclusiveAreDifferentCalculations(): void
    {
        $calculator = new VatCalculator();
        $rate = $this->rate('25.00');

        self::assertSame('250.00', $calculator->vatFromGross('1250.00', $rate));
        self::assertSame('312.50', $calculator->vatOnNet('1250.00', $rate));
    }

    public function testHandlesAFractionalRate(): void
    {
        // Finland's 25.5%: 1255.00 gross contains 255.00 VAT on 1000.00 net.
        $calculator = new VatCalculator();
        $rate = $this->rate('25.50');

        self::assertSame('255.00', $calculator->vatFromGross('1255.00', $rate));
        self::assertSame('1000.00', $calculator->netFromGross('1255.00', $rate));
    }

    public function testNetAndVatAlwaysSumBackToTheGross(): void
    {
        $calculator = new VatCalculator();

        // Amounts chosen to land on rounding boundaries, where an independently-computed net
        // and VAT drift a cent apart and the resulting voucher will not balance.
        foreach (['19.00', '21.00', '25.00', '25.50', '27.00', '7.00'] as $percent) {
            $rate = $this->rate($percent);

            foreach (['0.01', '0.05', '1.00', '9.99', '100.01', '1250.10', '99999.99'] as $gross) {
                $vat = $calculator->vatFromGross($gross, $rate);
                $net = $calculator->netFromGross($gross, $rate);

                self::assertSame(
                    bcadd($gross, '0', 2),
                    bcadd($net, $vat, 2),
                    sprintf('net + vat must equal gross for %s at %s%%', $gross, $percent),
                );
            }
        }
    }

    public function testRoundsHalfUpRatherThanTruncating(): void
    {
        // bcmath truncates by default; 0.005 must become 0.01, not 0.00.
        $calculator = new VatCalculator();

        self::assertSame('0.02', $calculator->vatOnNet('0.10', $this->rate('19.00')));
    }

    public function testHandlesAZeroRate(): void
    {
        $calculator = new VatCalculator();

        self::assertSame('0.00', $calculator->vatFromGross('100.00', $this->rate('0.00')));
        self::assertSame('100.00', $calculator->netFromGross('100.00', $this->rate('0.00')));
    }

    public function testHandlesACreditNote(): void
    {
        // Refunds carry negative amounts and must round away from zero the same way.
        self::assertSame('-250.00', (new VatCalculator())->vatFromGross('-1250.00', $this->rate('25.00')));
    }

    public function testRoundTripsFromNetToGrossAndBack(): void
    {
        $calculator = new VatCalculator();
        $rate = $this->rate('21.00');

        $gross = $calculator->grossFromNet('1000.00', $rate);

        self::assertSame('1210.00', $gross);
        self::assertSame('1000.00', $calculator->netFromGross($gross, $rate));
    }
}
