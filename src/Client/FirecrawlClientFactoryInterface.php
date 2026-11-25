<?php

// file generated with AI assistance: Claude Code - 2026-10-02 23:51:30 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\Client;

/**
 * Creates the client of one `firecrawl` connection. The seam of the bundle:
 * under `when@test` the host application aliases this interface to
 * {@see \Dmstr\Firecrawl\Testing\FakeFirecrawlClientFactory}.
 */
interface FirecrawlClientFactoryInterface
{
    /**
     * Performs no I/O.
     *
     * @param array<string, mixed> $configJson the connection's configJson
     *                                         (`base_url`, optional `api_key`, `timeout`, `verify_ssl`);
     *                                         unknown keys are ignored
     *
     * @throws \InvalidArgumentException when `base_url` is missing or not a non-empty string
     */
    public function create(array $configJson): FirecrawlClientInterface;
}
