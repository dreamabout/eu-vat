<?php

declare(strict_types=1);

namespace Kaikei\EuVat\Tedb;

use Kaikei\EuVat\Exception\TedbFault;

/**
 * The default transport.
 *
 * Note that TEDB answers a rejected request with HTTP 500 and a SOAP Fault body carrying the
 * actual reason — "The Member State \"NO\" does not exist" — so a non-2xx status is returned for
 * parsing rather than thrown away. Treating 500 as an opaque failure would discard the only
 * useful diagnostic the service gives.
 */
final class CurlTransport implements HttpTransport
{
    public function __construct(private readonly int $timeoutSeconds = 120)
    {
    }

    public function post(string $url, string $body, array $headers): string
    {
        $handle = curl_init($url);
        if (false === $handle) {
            throw TedbFault::unparseable('could not initialise a HTTP client.');
        }

        $formatted = [];
        foreach ($headers as $name => $value) {
            $formatted[] = $name.': '.$value;
        }

        curl_setopt_array($handle, [
            \CURLOPT_POST => true,
            \CURLOPT_POSTFIELDS => $body,
            \CURLOPT_HTTPHEADER => $formatted,
            \CURLOPT_RETURNTRANSFER => true,
            \CURLOPT_TIMEOUT => $this->timeoutSeconds,
            \CURLOPT_CONNECTTIMEOUT => 30,
            \CURLOPT_FOLLOWLOCATION => false,
            \CURLOPT_SSL_VERIFYPEER => true,
            \CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $response = curl_exec($handle);
        $error = curl_error($handle);
        $status = (int) curl_getinfo($handle, \CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        if (!\is_string($response)) {
            throw TedbFault::unparseable(sprintf('the request to TEDB failed: %s', '' === $error ? 'unknown error' : $error));
        }

        if ('' === trim($response)) {
            throw TedbFault::unparseable(sprintf('TEDB returned an empty body (HTTP %d).', $status));
        }

        // A fault arrives as HTTP 500 with the reason in the body; let the parser read it.
        return $response;
    }
}
