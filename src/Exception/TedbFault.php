<?php

declare(strict_types=1);

namespace Kaikei\EuVat\Exception;

/**
 * TEDB refused the request, or answered with something that is not a parseable response.
 *
 * Always thrown, never swallowed into an empty result. A sync that quietly produced no rows
 * would regenerate the snapshot as "every country has no rates", and the commit would look
 * like a real change.
 *
 * Note that one invalid member state faults the ENTIRE request — asking for `NO` alongside 26
 * valid states returns nothing at all — so the message carries every error the service listed.
 */
final class TedbFault extends \RuntimeException implements EuVatException
{
    /**
     * @param list<string> $errors
     */
    public static function fromErrors(string $faultString, array $errors): self
    {
        return new self(
            '' === $faultString && [] === $errors
                ? 'TEDB returned a SOAP fault with no detail.'
                : trim($faultString.([] === $errors ? '' : ': '.implode(' | ', $errors))),
        );
    }

    public static function unparseable(string $detail): self
    {
        return new self('Could not parse the TEDB response: '.$detail);
    }

    public static function ambiguousStandardRate(string $country, string $date, int $found): self
    {
        return new self(sprintf(
            'Expected exactly one non-qualifier standard rate for %s on %s, found %d. '
            .'A qualifier row is one whose comment reads "VAT - <Qualifier> - "; if TEDB has '
            .'changed that convention this rule needs revisiting, and guessing which row is the '
            .'country rate would be worse than stopping.',
            $country,
            $date,
            $found,
        ));
    }
}
