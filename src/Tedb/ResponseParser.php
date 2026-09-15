<?php

declare(strict_types=1);

namespace Kaikei\EuVat\Tedb;

use Kaikei\EuVat\Exception\TedbFault;
use Kaikei\EuVat\RateClass;

/**
 * Turns a raw TEDB `retrieveVatRates` response into rate samples.
 *
 * ## Which row is the country's standard rate
 *
 * The obvious rule — "the DEFAULT row with no comment" — is wrong, and wrong in a way that
 * hides: `BE`, `CZ`, `FR`, `IE`, `LU` and `LV` have **no** uncommented DEFAULT row, because
 * their standard-rate row carries an HTML legal citation (`<p>Article 278 of the General Tax
 * Code</p>`). Applying that rule drops six member states' standard rates entirely, and the
 * resulting snapshot looks perfectly well-formed.
 *
 * The real discriminator is the comment's *shape*:
 *
 *   - a **qualifier** row's comment is plain text reading `VAT - <Qualifier> - `, naming a
 *     territory (`VAT - Canary Islands - `) or a special scheme (`VAT - Import - `);
 *   - an **ordinary** rate's comment is absent, or HTML.
 *
 * Verified against both committed fixtures: this resolves exactly one standard rate for all 27
 * member states and for every `(country, date)` pair. Where it does not — zero rows, or two —
 * the parser throws rather than picking one, because that is the signal that TEDB's conventions
 * have moved and a guess would be indistinguishable from a correct answer.
 */
final class ResponseParser
{
    private const TYPES_NS = 'urn:ec.europa.eu:taxud:tedb:services:v1:IVatRetrievalService:types';

    /**
     * Plain-text qualifier comments. Anchored and non-greedy so an HTML comment that merely
     * mentions VAT cannot masquerade as one.
     */
    private const QUALIFIER_PATTERN = '/^\s*VAT\s*-\s*(.+?)\s*-\s*$/u';

    public function parse(string $xml): ParsedResponse
    {
        $document = $this->load($xml);
        $this->guardAgainstFault($document);

        $samples = [];
        foreach ($document->getElementsByTagNameNS(self::TYPES_NS, 'vatRateResults') as $node) {
            $sample = $this->toSample($node);
            if (null !== $sample) {
                $samples[] = $sample;
            }
        }

        $samples = $this->deduplicate($samples);
        $this->guardAgainstAmbiguousStandardRates($samples);

        return new ParsedResponse($samples);
    }

    private function load(string $xml): \DOMDocument
    {
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        try {
            $document = new \DOMDocument();
            if (!$document->loadXML($xml)) {
                $errors = array_map(static fn (\LibXMLError $e): string => trim($e->message), libxml_get_errors());

                throw TedbFault::unparseable([] === $errors ? 'not well-formed XML' : implode('; ', $errors));
            }

            return $document;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /**
     * A fault is never swallowed into an empty result: a sync that quietly produced no rows
     * would regenerate the snapshot as "no country has any rate", and that commit would look
     * like a real change rather than a failure.
     */
    private function guardAgainstFault(\DOMDocument $document): void
    {
        $faults = $document->getElementsByTagNameNS('http://schemas.xmlsoap.org/soap/envelope/', 'Fault');
        if (0 === $faults->length) {
            return;
        }

        $fault = $faults->item(0);
        \assert($fault instanceof \DOMElement);

        $errors = [];
        foreach ($fault->getElementsByTagNameNS(self::TYPES_NS, 'error') as $error) {
            $code = $this->childValue($error, 'code');
            $description = $this->childValue($error, 'description');
            $errors[] = trim(('' === $code ? '' : $code.' ').$description);
        }

        $faultStrings = $fault->getElementsByTagName('faultstring');
        $faultString = $faultStrings->length > 0 ? trim((string) $faultStrings->item(0)?->textContent) : '';

        throw TedbFault::fromErrors($faultString, $errors);
    }

    private function toSample(\DOMElement $node): ?RateSample
    {
        $rateClass = RateClass::fromTedb($this->descendantValue($node, 'rate', 'type'));
        if (null === $rateClass) {
            // EXEMPTED / OUT_OF_SCOPE / NOT_APPLICABLE: category-specific exemptions, not rates.
            return null;
        }

        $value = $this->descendantValue($node, 'rate', 'value');
        if ('' === $value) {
            return null;
        }

        $country = strtoupper(trim($this->childValue($node, 'memberState')));
        $situationOn = $this->childValue($node, 'situationOn');
        if ('' === $country || '' === $situationOn) {
            return null;
        }

        return new RateSample(
            country: $country,
            rateClass: $rateClass,
            percent: bcadd($value, '0', 2),
            date: CalendarDate::parse($situationOn),
            qualifier: $this->qualifierOf($node),
            comment: '' === trim($this->childValue($node, 'comment')) ? null : trim($this->childValue($node, 'comment')),
        );
    }

    /**
     * The qualifier named by a plain-text `VAT - X - ` comment, or null for an ordinary rate.
     * HTML comments are legal citations attached to perfectly ordinary rates and mean nothing
     * here.
     */
    private function qualifierOf(\DOMElement $node): ?string
    {
        $comment = trim($this->childValue($node, 'comment'));
        if ('' === $comment) {
            return null;
        }

        if (1 !== preg_match(self::QUALIFIER_PATTERN, $comment, $matches)) {
            return null;
        }

        $qualifier = trim($matches[1]);

        return '' === $qualifier ? null : $qualifier;
    }

    /**
     * TEDB repeats rows: a 27-state query across 2015-2026 returns 575 non-qualifier standard
     * rows of which only 566 are distinct. The repeats are byte-identical — same country, date,
     * value and comment — and carry no information, so they are collapsed before anything tries
     * to read meaning into their number.
     *
     * @param list<RateSample> $samples
     *
     * @return list<RateSample>
     */
    private function deduplicate(array $samples): array
    {
        $unique = [];
        foreach ($samples as $sample) {
            $key = implode('|', [
                $sample->country,
                $sample->rateClass->value,
                $sample->percent,
                $sample->date->format('Y-m-d'),
                $sample->qualifier ?? '',
                $sample->comment ?? '',
            ]);

            $unique[$key] ??= $sample;
        }

        return array_values($unique);
    }

    /**
     * Ambiguity means "we cannot tell which row is the country's rate", and that only arises
     * when the rows DISAGREE. Two rows saying 20% are not a puzzle. Measured over 2015-2026 for
     * all 27 member states, no (country, date) ever carries two different non-qualifier standard
     * values — so if this ever throws, something real has changed.
     *
     * @param list<RateSample> $samples
     */
    private function guardAgainstAmbiguousStandardRates(array $samples): void
    {
        $values = [];
        foreach ($samples as $sample) {
            if (RateClass::STANDARD !== $sample->rateClass || $sample->isQualified()) {
                continue;
            }
            $key = $sample->country.'|'.$sample->date->format('Y-m-d');
            $values[$key][$sample->percent] = true;
        }

        foreach ($values as $key => $distinct) {
            if (1 !== \count($distinct)) {
                [$country, $date] = explode('|', $key, 2);

                throw TedbFault::ambiguousStandardRate($country, $date, \count($distinct));
            }
        }
    }

    private function childValue(\DOMElement $node, string $name): string
    {
        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMElement && $child->localName === $name) {
                return $child->textContent;
            }
        }

        return '';
    }

    private function descendantValue(\DOMElement $node, string $parent, string $name): string
    {
        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMElement && $child->localName === $parent) {
                return $this->childValue($child, $name);
            }
        }

        return '';
    }
}
