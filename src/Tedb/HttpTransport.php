<?php

declare(strict_types=1);

namespace Kaikei\EuVat\Tedb;

/**
 * The one place this package touches the network.
 *
 * An interface rather than a direct curl call so the whole sync path — envelope construction,
 * transport, parsing, interval derivation, snapshot writing — can be exercised end to end from
 * a committed fixture. A sync pipeline that can only be tested against the live service is one
 * that gets tested rarely and changed nervously.
 */
interface HttpTransport
{
    /**
     * @param array<string, string> $headers
     *
     * @throws \Kaikei\EuVat\Exception\TedbFault on any transport-level failure
     */
    public function post(string $url, string $body, array $headers): string;
}
