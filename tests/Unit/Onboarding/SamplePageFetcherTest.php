<?php

// file generated with AI assistance: Claude Code - 2026-10-13 21:47:18 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\Tests\Unit\Onboarding;

use Dmstr\Firecrawl\Onboarding\SamplePageFetcher;
use Dmstr\Firecrawl\Testing\FakeFirecrawlClientFactory;
use PHPUnit\Framework\TestCase;

final class SamplePageFetcherTest extends TestCase
{
    public function testPendingJobIsReportedWithProgressAndCollectedLater(): void
    {
        $factory = new FakeFirecrawlClientFactory();
        $client = $factory->create(['base_url' => 'https://fc.example.org']);
        $factory->queue('POST', '/v2/batch/scrape', ['success' => true, 'id' => 'b-1']);
        $factory->queue('GET', '/v2/batch/scrape/b-1', ['status' => 'scraping', 'total' => 2, 'completed' => 1]);
        $sleeps = [];
        $fetcher = new SamplePageFetcher(static function (float $s) use (&$sleeps): void {
            $sleeps[] = $s;
        });

        self::assertSame(['jobId' => 'b-1', 'kind' => 'batch'], $fetcher->start($client, ['https://example.org/a', 'https://example.org/b']));

        $first = $fetcher->collect($client, 'batch', 'b-1', 0.0);

        self::assertSame(['status' => 'pending', 'total' => 2, 'completed' => 1, 'pages' => []], $first);
        self::assertSame([], $sleeps, 'no wait when the budget is used up');

        $factory->queue('GET', '/v2/batch/scrape/b-1', ['status' => 'scraping']);
        $factory->queue('GET', '/v2/batch/scrape/b-1', ['status' => 'completed', 'total' => 2, 'completed' => 2, 'data' => [
            ['rawHtml' => '<html></html>', 'metadata' => ['url' => 'https://example.org/a', 'statusCode' => 200, 'title' => 'A']],
            ['metadata' => ['statusCode' => 200]],
        ]]);

        $second = $fetcher->collect($client, 'batch', 'b-1', 10.0);

        self::assertSame('completed', $second['status']);
        self::assertSame([['url' => 'https://example.org/a', 'statusCode' => 200, 'title' => 'A', 'html' => '<html></html>', 'error' => null]], $second['pages']);
        self::assertSame([SamplePageFetcher::POLL_SECONDS], $sleeps);
        self::assertSame(1, $factory->getRequestCount(null, 'POST', '/v2/batch/scrape'));
    }

    public function testOneUrlStartsACrawlWithTheLimit(): void
    {
        $factory = new FakeFirecrawlClientFactory();
        $client = $factory->create(['base_url' => 'https://fc.example.org']);
        $factory->queue('POST', '/v2/crawl', ['success' => true, 'id' => 'c-1']);

        self::assertSame(['jobId' => 'c-1', 'kind' => 'crawl'], new SamplePageFetcher()->start($client, ['https://example.org/'], 7));

        $body = $factory->getRequests(null, 'POST', '/v2/crawl')[0]['body'];
        self::assertSame('https://example.org/', $body['url']);
        self::assertSame(7, $body['limit']);
        self::assertSame(2, $body['maxDiscoveryDepth']);
    }
}
// - revised 2026-10-14
