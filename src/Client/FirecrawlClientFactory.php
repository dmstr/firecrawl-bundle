<?php

// file generated with AI assistance: Claude Code - 2026-10-02 23:51:30 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\Client;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Production {@see FirecrawlClientFactoryInterface}: a {@see FirecrawlClient}
 * on the app's HttpClient, logging to the Monolog channel `firecrawl`.
 */
final class FirecrawlClientFactory implements FirecrawlClientFactoryInterface
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $firecrawlLogger = new NullLogger(),
    ) {
    }

    public function create(array $configJson): FirecrawlClientInterface
    {
        return new FirecrawlClient($this->httpClient, $configJson, $this->firecrawlLogger);
    }
}
