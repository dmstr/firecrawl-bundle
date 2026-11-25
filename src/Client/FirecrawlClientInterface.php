<?php

// file generated with AI assistance: Claude Code - 2026-10-02 23:51:30 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\Client;

/**
 * Firecrawl v2 REST API of one connection (one base URL and credentials).
 *
 * Request bodies are forwarded verbatim as JSON; responses are the decoded
 * JSON objects as associative arrays. Every method throws
 * {@see FirecrawlApiException}: with the status code and up to 500 bytes of
 * the body on HTTP >= 400, with status null and the Symfony transport
 * exception as previous on a transport failure, and as
 * {@see FirecrawlProtocolException} when a 2xx body is not a JSON object.
 *
 * Obtain instances from {@see FirecrawlClientFactoryInterface::create()}.
 */
interface FirecrawlClientInterface
{
    /**
     * POST /v2/scrape (synchronous single-page scrape).
     *
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed> decoded JSON of POST /v2/scrape
     *
     * @throws FirecrawlApiException
     */
    public function scrape(array $body): array;

    /**
     * POST /v2/map (synchronous URL discovery).
     *
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     *
     * @throws FirecrawlApiException
     */
    public function map(array $body): array;

    /**
     * POST /v2/crawl — the decoded response carries `id` and `url`.
     *
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     *
     * @throws FirecrawlApiException
     */
    public function crawl(array $body): array;

    /**
     * POST /v2/batch/scrape.
     *
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     *
     * @throws FirecrawlApiException
     */
    public function batchScrape(array $body): array;

    /**
     * GET /v2/crawl/{id} (status and first result page).
     *
     * @return array<string, mixed>
     *
     * @throws FirecrawlApiException
     */
    public function getCrawl(string $id): array;

    /**
     * GET /v2/batch/scrape/{id}.
     *
     * @return array<string, mixed>
     *
     * @throws FirecrawlApiException
     */
    public function getBatchScrape(string $id): array;

    /**
     * GET /v2/crawl/active — `{success, crawls: [{id, teamId, url, options}]}`.
     *
     * @return array<string, mixed>
     *
     * @throws FirecrawlApiException
     */
    public function activeCrawls(): array;

    /**
     * GET of an absolute URL the API handed out (`next` pagination). Scheme,
     * host and port are replaced by the configured base_url's (SSRF guard),
     * the path and query are kept.
     *
     * @return array<string, mixed>
     *
     * @throws FirecrawlApiException
     * @throws \InvalidArgumentException when the URL has no path
     */
    public function getUrl(string $absoluteUrl): array;

    /**
     * The configured base_url without a trailing slash.
     */
    public function getBaseUrl(): string;
}
