<?php

declare(strict_types=1);

namespace Kaikei\EuVat\Tedb;

/**
 * Calls the Commission's `retrieveVatRates` service.
 *
 * ## The namespace trap
 *
 * The request message element `retrieveVatRatesReqMsg` lives in the `...:IVatRetrievalService`
 * namespace, but its children — `memberStates`, `from`, `to` — live in
 * `...:IVatRetrievalService:types`, because the schema sets `elementFormDefault="qualified"`.
 * Putting the children in the message namespace, which is the obvious reading, returns
 * `TEDB-ERR-2 - Request is not valid / The XSD validation failed` with no indication of why.
 *
 * ## Greece, and the all-or-nothing rule
 *
 * Greece must be requested as `EL`; `GR` is rejected outright. `NO` and `CH` do not exist in
 * TEDB at all. And one bad code faults the ENTIRE request — asking for 26 valid states plus
 * `NO` returns nothing — so the member state list has to be exactly right.
 */
final class TedbClient
{
    public const ENDPOINT = 'https://ec.europa.eu/taxation_customs/tedb/ws/VatRetrievalService';

    private const SOAP_ACTION = 'urn:ec.europa.eu:taxud:tedb:services:v1:VatRetrievalService/RetrieveVatRates';
    private const MESSAGE_NS = 'urn:ec.europa.eu:taxud:tedb:services:v1:IVatRetrievalService';
    private const TYPES_NS = 'urn:ec.europa.eu:taxud:tedb:services:v1:IVatRetrievalService:types';

    /**
     * The EU-27, with Greece as `EL`. Not a convenience list: because one invalid code fails the
     * whole call, this is the exact set the service accepts.
     *
     * @var list<string>
     */
    public const MEMBER_STATES = [
        'AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'EL', 'ES', 'FI', 'FR', 'HR', 'HU',
        'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PL', 'PT', 'RO', 'SE', 'SI', 'SK',
    ];

    public function __construct(
        private readonly HttpTransport $transport = new CurlTransport(),
        private readonly ResponseParser $parser = new ResponseParser(),
        private readonly string $endpoint = self::ENDPOINT,
    ) {
    }

    /**
     * @param list<string> $memberStates
     */
    public function retrieveVatRates(
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        array $memberStates = self::MEMBER_STATES,
    ): ParsedResponse {
        $response = $this->transport->post(
            $this->endpoint,
            $this->envelope($from, $to, $memberStates),
            [
                'Content-Type' => 'text/xml;charset=UTF-8',
                'SOAPAction' => self::SOAP_ACTION,
            ],
        );

        return $this->parser->parse($response);
    }

    /**
     * @param list<string> $memberStates
     */
    public function envelope(\DateTimeImmutable $from, \DateTimeImmutable $to, array $memberStates): string
    {
        $codes = '';
        foreach ($memberStates as $code) {
            $codes .= sprintf('<t:isoCode>%s</t:isoCode>', htmlspecialchars(strtoupper(trim($code)), \ENT_XML1));
        }

        return sprintf(
            '<?xml version="1.0" encoding="UTF-8"?>'
            .'<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:m="%s" xmlns:t="%s">'
            .'<soapenv:Body><m:retrieveVatRatesReqMsg>'
            .'<t:memberStates>%s</t:memberStates>'
            .'<t:from>%s</t:from>'
            .'<t:to>%s</t:to>'
            .'</m:retrieveVatRatesReqMsg></soapenv:Body></soapenv:Envelope>',
            self::MESSAGE_NS,
            self::TYPES_NS,
            $codes,
            $from->format('Y-m-d'),
            $to->format('Y-m-d'),
        );
    }
}
