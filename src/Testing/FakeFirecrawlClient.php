<?php

// file generated with AI assistance: Claude Code - 2026-10-02 23:51:30 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\Testing;

use Dmstr\Firecrawl\Client\FirecrawlClient;
use Dmstr\Firecrawl\Client\FirecrawlClientInterface;

/**
 * Client handed out by {@see FakeFirecrawlClientFactory}: every call is
 * recorded and answered by the factory's scripts; nothing leaves the
 * process.
 */
final class FakeFirecrawlClient implements FirecrawlClientInterface
{
    public function __construct(
        private readonly FakeFirecrawlClientFactory $factory,
        private readonly string $baseUrl,
    ) {
    }

    public function scrape(array $body): array
    {
        return $this->factory->handle($this->baseUrl, 'POST', '/v2/scrape', $body);
    }

    public function map(array $body): array
    {
        return $this->factory->handle($this->baseUrl, 'POST', '/v2/map', $body);
    }

    public function crawl(array $body): array
    {
        return $this->factory->handle($this->baseUrl, 'POST', '/v2/crawl', $body);
    }

    public function batchScrape(array $body): array
    {
        return $this->factory->handle($this->baseUrl, 'POST', '/v2/batch/scrape', $body);
    }

    public function getCrawl(string $id): array
    {
        return $this->factory->handle($this->baseUrl, 'GET', '/v2/crawl/'.rawurlencode($id), null);
    }

    public function getBatchScrape(string $id): array
    {
        return $this->factory->handle($this->baseUrl, 'GET', '/v2/batch/scrape/'.rawurlencode($id), null);
    }

    public function activeCrawls(): array
    {
        return $this->factory->handle($this->baseUrl, 'GET', '/v2/crawl/active', null);
    }

    /**
     * Matched on the path plus query of $absoluteUrl, as the real client
     * requests it after replacing scheme and host.
     */
    public function getUrl(string $absoluteUrl): array
    {
        return $this->factory->handle($this->baseUrl, 'GET', FirecrawlClient::pathAndQuery($absoluteUrl), null);
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }
}
