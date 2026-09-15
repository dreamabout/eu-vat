<?php

declare(strict_types=1);

namespace Kaikei\EuVat\Tedb;

use Kaikei\EuVat\Exception\TedbFault;

/**
 * Replays a committed response instead of calling the service.
 *
 * Used by `bin/regenerate-rates --fixture=...` and by the test suite, so the entire sync
 * pipeline is exercised offline and deterministically.
 */
final class FixtureTransport implements HttpTransport
{
    public function __construct(private readonly string $path)
    {
    }

    public function post(string $url, string $body, array $headers): string
    {
        $contents = @file_get_contents($this->path);
        if (false === $contents) {
            throw TedbFault::unparseable(sprintf('cannot read the fixture at "%s".', $this->path));
        }

        return $contents;
    }
}
