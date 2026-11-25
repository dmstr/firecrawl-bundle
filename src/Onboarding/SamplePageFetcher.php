<?php

// file generated with AI assistance: Claude Code - 2026-10-13 21:47:18 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\Onboarding;

use Dmstr\Firecrawl\Client\FirecrawlClientInterface;
use Dmstr\Firecrawl\Client\FirecrawlProtocolException;

/**
 * The Firecrawl job of the boilerplate step: it starts the job that fetches
 * the raw HTML of the sample pages and collects the pages once it is done.
 *
 *  - One start URL: a crawl from that URL (`limit` pages, at most two
 *    levels deep), because repeating regions can only be found by
 *    comparing several pages of the site.
 *  - Several start URLs: a batch scrape of exactly these pages.
 *
 * Both with `formats: [rawHtml]` and `onlyMainContent: false`, so the page
 * chrome is still in the HTML. Starting returns at once; collecting polls
 * for at most {@see BUDGET_SECONDS}, because a tool call must end before
 * the MCP client gives up (Neuron's streamable HTTP transport: 30 s unless
 * the connection sets `timeout`) and within the 60 s of the fpm pool. A job
 * that is not done by then is answered as `pending`, and the caller asks
 * again. Firecrawl keeps job results for 24 h.
 */
final class SamplePageFetcher
{
    public const string KIND_CRAWL = 'crawl';

    public const string KIND_BATCH = 'batch';

    public const int DEFAULT_LIMIT = 10;

    public const int MAX_LIMIT = 25;

    public const float BUDGET_SECONDS = 20.0;

    public const float POLL_SECONDS = 2.0;

    /** Result pages of a crawl are followed through `next` at most this often. */
    private const int MAX_RESULT_PAGES = 10;

    private const array SCRAPE_OPTIONS = ['formats' => ['rawHtml'], 'onlyMainContent' => false];

    /** @var \Closure(float): void */
    private readonly \Closure $sleep;

    /**
     * @param (\Closure(float): void)|null $sleep test seam; default usleep
     */
    public function __construct(?\Closure $sleep = null)
    {
        $this->sleep = $sleep ?? static function (float $seconds): void {
            usleep((int) ($seconds * 1_000_000));
        };
    }

    /**
     * @param list<string> $urls 1 to 5 start URLs
     *
     * @return array{jobId: string, kind: 'crawl'|'batch'}
     *
     * @throws \Dmstr\Firecrawl\Client\FirecrawlApiException including FirecrawlProtocolException
     */
    public function start(FirecrawlClientInterface $client, array $urls, int $limit = self::DEFAULT_LIMIT): array
    {
        if (1 === \count($urls)) {
            $kind = self::KIND_CRAWL;
            $started = $client->crawl([
                'url' => $urls[0],
                'limit' => $limit,
                'maxDiscoveryDepth' => 2,
                'sitemap' => 'include',
                'scrapeOptions' => self::SCRAPE_OPTIONS,
            ]);
        } else {
            $kind = self::KIND_BATCH;
            $started = $client->batchScrape(['urls' => array_values($urls), 'ignoreInvalidURLs' => true] + self::SCRAPE_OPTIONS);
        }

        $id = \is_string($started['id'] ?? null) ? $started['id'] : throw new FirecrawlProtocolException(\sprintf('Firecrawl %s returned no id.', $kind), null, json_encode($started) ?: null);

        return ['jobId' => $id, 'kind' => $kind];
    }

    /**
     * @param 'crawl'|'batch' $kind
     *
     * @return array{status: 'completed'|'pending'|'failed', total: int|null, completed: int|null, pages: list<array{url: string, statusCode: int|null, title: string|null, html: string, error: string|null}>}
     *
     * @throws \Dmstr\Firecrawl\Client\FirecrawlApiException including FirecrawlProtocolException
     */
    public function collect(FirecrawlClientInterface $client, string $kind, string $jobId, float $budgetSeconds = self::BUDGET_SECONDS): array
    {
        $deadline = microtime(true) + $budgetSeconds;
        while (true) {
            $status = self::KIND_CRAWL === $kind ? $client->getCrawl($jobId) : $client->getBatchScrape($jobId);
            $state = $status['status'] ?? null;
            $progress = [
                'total' => \is_int($status['total'] ?? null) ? $status['total'] : null,
                'completed' => \is_int($status['completed'] ?? null) ? $status['completed'] : null,
            ];
            if ('completed' === $state || 'failed' === $state) {
                return ['status' => $state] + $progress + ['pages' => self::pages($client, $status)];
            }
            if (microtime(true) + self::POLL_SECONDS > $deadline) {
                return ['status' => 'pending'] + $progress + ['pages' => []];
            }
            ($this->sleep)(self::POLL_SECONDS);
        }
    }

    /**
     * The pages of a finished job, following `next` for further result
     * pages (the client requests it on its own instance, see
     * {@see FirecrawlClientInterface::getUrl()}).
     *
     * @param array<string, mixed> $status
     *
     * @return list<array{url: string, statusCode: int|null, title: string|null, html: string, error: string|null}>
     */
    private static function pages(FirecrawlClientInterface $client, array $status): array
    {
        $pages = [];
        for ($i = 0; $i < self::MAX_RESULT_PAGES; ++$i) {
            foreach (\is_array($status['data'] ?? null) ? $status['data'] : [] as $item) {
                if (!\is_array($item)) {
                    continue;
                }
                $metadata = \is_array($item['metadata'] ?? null) ? $item['metadata'] : [];
                $url = $metadata['sourceURL'] ?? $metadata['url'] ?? null;
                if (!\is_string($url)) {
                    continue;
                }
                $pages[] = [
                    'url' => $url,
                    'statusCode' => \is_int($metadata['statusCode'] ?? null) ? $metadata['statusCode'] : null,
                    'title' => \is_string($metadata['title'] ?? null) ? $metadata['title'] : null,
                    'html' => \is_string($item['rawHtml'] ?? null) ? $item['rawHtml'] : '',
                    'error' => \is_string($metadata['error'] ?? null) ? $metadata['error'] : null,
                ];
            }
            $next = $status['next'] ?? null;
            if (!\is_string($next) || '' === $next) {
                break;
            }
            $status = $client->getUrl($next);
        }

        return $pages;
    }
}
// - revised 2026-10-14
