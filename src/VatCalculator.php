<?php

declare(strict_types=1);

namespace Kaikei\EuVat;

/**
 * VAT arithmetic on decimal strings.
 *
 * **Gross-first, deliberately.** e-conomic momskoder book the gross amount and split the VAT
 * out of it; they do not add VAT on top of a net figure. So `vatFromGross()` is the primary
 * operation here rather than something derived from `vatOnNet()`, and the two are not the same
 * calculation: 1250.00 at 25% contains 250.00 of VAT, while 1250.00 *plus* 25% is 312.50.
 * Reaching for the wrong one produces a number that looks entirely reasonable and is 25% wrong.
 *
 * Nothing here ever touches a float. `(float) '1250.10'` is not 1250.10, and a cent lost that
 * way reappears as a voucher that will not balance.
 */
final class VatCalculator
{
    /** Working scale for intermediate steps, well beyond the 2 dp the results are rounded to. */
    private const WORKING_SCALE = 10;

    public function __construct(
        /** Decimal places in the result. Money is 2; override only with a reason. */
        private readonly int $scale = 2,
        /** PHP_ROUND_HALF_UP matches Danish rounding convention and e-conomic's behaviour. */
        private readonly int $roundingMode = \PHP_ROUND_HALF_UP,
    ) {
    }

    /**
     * The VAT contained in a VAT-inclusive amount: `gross * rate / (100 + rate)`.
     */
    public function vatFromGross(string $gross, Rate $rate): string
    {
        $divisor = bcadd('100', $rate->percent, self::WORKING_SCALE);

        if (0 === bccomp($divisor, '0', self::WORKING_SCALE)) {
            throw new \InvalidArgumentException('A rate of -100% would divide by zero.');
        }

        return $this->round(bcdiv(
            bcmul($gross, $rate->percent, self::WORKING_SCALE),
            $divisor,
            self::WORKING_SCALE,
        ));
    }

    /**
     * The net amount inside a VAT-inclusive amount.
     *
     * Derived by subtracting the rounded VAT rather than by a second division, so that
     * `net + vat === gross` exactly. Computing both independently can leave them a cent apart,
     * and a voucher built from those two numbers will not balance.
     */
    public function netFromGross(string $gross, Rate $rate): string
    {
        return bcsub($this->round($gross), $this->vatFromGross($gross, $rate), $this->scale);
    }

    /** The VAT to add to a VAT-exclusive amount: `net * rate / 100`. */
    public function vatOnNet(string $net, Rate $rate): string
    {
        return $this->round(bcdiv(bcmul($net, $rate->percent, self::WORKING_SCALE), '100', self::WORKING_SCALE));
    }

    /** The VAT-inclusive total for a VAT-exclusive amount. */
    public function grossFromNet(string $net, Rate $rate): string
    {
        return bcadd($this->round($net), $this->vatOnNet($net, $rate), $this->scale);
    }

    /**
     * bcmath truncates rather than rounds, so rounding is explicit. Done on the decimal string
     * via a half-up adjustment, never by casting through a float.
     */
    private function round(string $amount): string
    {
        $negative = str_starts_with(trim($amount), '-');
        $magnitude = $negative ? substr(trim($amount), 1) : trim($amount);

        $increment = \PHP_ROUND_HALF_UP === $this->roundingMode
            ? '0.'.str_repeat('0', $this->scale).'5'
            : '0';

        $rounded = bcadd($magnitude, $increment, $this->scale);

        return $negative && 0 !== bccomp($rounded, '0', $this->scale) ? '-'.$rounded : $rounded;
    }
}
