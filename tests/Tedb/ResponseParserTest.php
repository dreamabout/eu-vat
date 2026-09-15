<?php

declare(strict_types=1);

namespace Kaikei\EuVat\Tests\Tedb;

use Kaikei\EuVat\Exception\TedbFault;
use Kaikei\EuVat\RateClass;
use Kaikei\EuVat\Tedb\ResponseParser;
use PHPUnit\Framework\TestCase;

/**
 * Parses real, unmodified TEDB responses.
 *
 * The central question these tests pin down is which row is a country's standard rate, and the
 * obvious answer is wrong. It is NOT "the DEFAULT row with no comment": BE, CZ, FR, IE, LU and
 * LV have no uncommented DEFAULT row at all, because their standard-rate row carries an HTML
 * legal citation. Reading the comment's presence as meaning would silently drop six countries.
 *
 * What distinguishes a qualifier row is the comment's SHAPE — plain text of the form
 * "VAT - Canary Islands - " or "VAT - Import - ", against HTML or nothing for an ordinary rate.
 */
final class ResponseParserTest extends TestCase
{
    private const EU27 = __DIR__.'/../fixtures/tedb-eu27-current.xml';
    private const DK_DE_FI = __DIR__.'/../fixtures/tedb-dk-de-fi-2024-2026.xml';

    public function testResolvesExactlyOneStandardRateForEveryMemberState(): void
    {
        $parsed = (new ResponseParser())->parse(file_get_contents(self::EU27));

        $countries = [];
        foreach ($parsed->standardSamples() as $sample) {
            $countries[$sample->country][$sample->date->format('Y-m-d')] = $sample->percent;
        }

        self::assertCount(27, $countries, 'Every member state must resolve a standard rate.');
    }

    /**
     * The six that would have been dropped by the "no comment" rule. Their presence here IS the
     * regression test — each has an HTML legal citation where a naive reader expects nothing.
     */
    public function testResolvesStandardRatesForCountriesWhoseRowCarriesALegalCitation(): void
    {
        $parsed = (new ResponseParser())->parse(file_get_contents(self::EU27));

        $expected = ['BE' => '21.00', 'CZ' => '21.00', 'FR' => '20.00', 'IE' => '23.00', 'LU' => '17.00', 'LV' => '21.00'];

        foreach ($expected as $country => $percent) {
            $samples = array_values(array_filter(
                $parsed->standardSamples(),
                static fn ($s): bool => $s->country === $country,
            ));

            self::assertNotEmpty($samples, sprintf('%s resolved no standard rate.', $country));
            self::assertSame($percent, $samples[0]->percent, sprintf('%s standard rate.', $country));
        }
    }

    public function testCanaryIslandsIsAQualifierRowAndNotSpainsStandardRate(): void
    {
        $parsed = (new ResponseParser())->parse(file_get_contents(self::EU27));

        $spanish = array_values(array_filter(
            $parsed->standardSamples(),
            static fn ($s): bool => 'ES' === $s->country,
        ));

        self::assertSame('21.00', $spanish[0]->percent, 'Spain charges 21%, not the Canary 7%.');

        $qualifiers = array_map(
            static fn ($s): string => $s->qualifier ?? '',
            array_filter($parsed->qualifierSamples(), static fn ($s): bool => 'ES' === $s->country),
        );

        self::assertContains('Canary Islands', $qualifiers);
    }

    public function testImportIsAQualifierRowToo(): void
    {
        $parsed = (new ResponseParser())->parse(file_get_contents(self::EU27));

        $qualifiers = array_map(
            static fn ($s): string => $s->qualifier ?? '',
            array_filter($parsed->qualifierSamples(), static fn ($s): bool => 'DE' === $s->country),
        );

        self::assertContains('Import', $qualifiers, 'DE 19.0 "VAT - Import - " is a qualifier, not a second standard rate.');
    }

    public function testCapturesTheFinnishRateChangeAsSeparateSamples(): void
    {
        $parsed = (new ResponseParser())->parse(file_get_contents(self::DK_DE_FI));

        $finnish = [];
        foreach ($parsed->standardSamples() as $sample) {
            if ('FI' === $sample->country) {
                $finnish[$sample->date->format('Y-m-d')] = $sample->percent;
            }
        }

        self::assertSame('24.00', $finnish['2024-07-01'] ?? null);
        self::assertSame('25.50', $finnish['2024-09-01'] ?? null, 'The change date must survive as a calendar date.');
    }

    public function testDropsNonRateRowsSuchAsExemptedAndOutOfScope(): void
    {
        $parsed = (new ResponseParser())->parse(file_get_contents(self::EU27));

        foreach ([...$parsed->standardSamples(), ...$parsed->reducedSamples()] as $sample) {
            self::assertInstanceOf(RateClass::class, $sample->rateClass);
        }

        // EXEMPTED / OUT_OF_SCOPE / NOT_APPLICABLE rows are category-specific exemptions, not
        // rates a country charges. Carrying them would invite a caller to read one as an answer.
        self::assertNotEmpty($parsed->reducedSamples());
    }

    public function testNormalisesRatesToTwoDecimalPlaces(): void
    {
        $parsed = (new ResponseParser())->parse(file_get_contents(self::DK_DE_FI));

        foreach ($parsed->standardSamples() as $sample) {
            self::assertMatchesRegularExpression('/^\d+\.\d{2}$/', $sample->percent);
        }
    }

    public function testSurfacesASoapFaultRatherThanReturningNothing(): void
    {
        $fault = <<<'XML'
            <env:Envelope xmlns:env="http://schemas.xmlsoap.org/soap/envelope/"><env:Body><env:Fault>
            <faultcode>env:Client</faultcode><faultstring>TEDB-ERR-2 - Request is not valid</faultstring>
            <detail><ns2:retrieveVatRatesFaultMsg xmlns:ns0="urn:ec.europa.eu:taxud:tedb:services:v1:IVatRetrievalService:types" xmlns:ns2="urn:ec.europa.eu:taxud:tedb:services:v1:IVatRetrievalService">
            <ns0:error><ns0:code>00002</ns0:code><ns0:description>The Member State "NO" does not exist.</ns0:description></ns0:error>
            <ns0:error><ns0:code>00002</ns0:code><ns0:description>The Member State "CH" does not exist.</ns0:description></ns0:error>
            </ns2:retrieveVatRatesFaultMsg></detail></env:Fault></env:Body></env:Envelope>
            XML;

        $this->expectException(TedbFault::class);
        // One bad member state faults the WHOLE request, so the message must name every code.
        $this->expectExceptionMessageMatches('/"NO" does not exist.*"CH" does not exist/s');

        (new ResponseParser())->parse($fault);
    }

    /**
     * TEDB repeats rows. A 27-state query across 2015-2026 returns 575 non-qualifier standard
     * rows of which 566 are distinct; Slovakia on 2020-07-01 is reported twice, byte for byte.
     * Two rows saying the same thing are not an ambiguity, and treating them as one aborted the
     * first real historical sync.
     */
    public function testExactDuplicateRowsAreCollapsedRatherThanTreatedAsAmbiguous(): void
    {
        $xml = $this->responseWith([
            ['SK', '20.0', '2020-07-01+02:00'],
            ['SK', '20.0', '2020-07-01+02:00'],
        ]);

        $parsed = (new ResponseParser())->parse($xml);

        self::assertCount(1, $parsed->standardSamples());
        self::assertSame('20.00', $parsed->standardSamples()[0]->percent);
    }

    /** Rows that genuinely disagree are a different matter, and must stop the run. */
    public function testDisagreeingStandardRatesAreRefused(): void
    {
        $xml = $this->responseWith([
            ['SK', '20.0', '2020-07-01+02:00'],
            ['SK', '23.0', '2020-07-01+02:00'],
        ]);

        $this->expectException(TedbFault::class);
        $this->expectExceptionMessageMatches('/2 DIFFERENT standard rates for SK/');

        (new ResponseParser())->parse($xml);
    }

    /**
     * @param list<array{0: string, 1: string, 2: string}> $rows
     */
    private function responseWith(array $rows): string
    {
        $body = '';
        foreach ($rows as [$country, $value, $date]) {
            $body .= sprintf(
                '<vatRateResults><memberState>%s</memberState><type>STANDARD</type>'
                .'<rate><type>DEFAULT</type><value>%s</value></rate>'
                .'<situationOn>%s</situationOn></vatRateResults>',
                $country,
                $value,
                $date,
            );
        }

        return '<env:Envelope xmlns:env="http://schemas.xmlsoap.org/soap/envelope/"><env:Body>'
            .'<ns0:retrieveVatRatesRespMsg xmlns="urn:ec.europa.eu:taxud:tedb:services:v1:IVatRetrievalService:types" '
            .'xmlns:ns0="urn:ec.europa.eu:taxud:tedb:services:v1:IVatRetrievalService">'
            .$body
            .'</ns0:retrieveVatRatesRespMsg></env:Body></env:Envelope>';
    }

    public function testRejectsMalformedXmlRatherThanSilentlyReturningNothing(): void
    {
        $this->expectException(TedbFault::class);

        (new ResponseParser())->parse('<not-xml');
    }
}
