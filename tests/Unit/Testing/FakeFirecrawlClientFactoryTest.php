<?php

// file generated with AI assistance: Claude Code - 2026-10-02 23:51:30 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\Tests\Unit\Testing;

use Dmstr\Firecrawl\Client\FirecrawlApiException;
use Dmstr\Firecrawl\Testing\FakeFirecrawlClient;
use Dmstr\Firecrawl\Testing\FakeFirecrawlClientFactory;
use PHPUnit\Framework\TestCase;

/**
 * Kernel-free checks of the test seam itself: scripting precedence, FIFO
 * queues, failures, recording and the refusal of unscripted calls.
 */
final class FakeFirecrawlClientFactoryTest extends TestCase
{
    private const string A = 'https://a.example.org';
    private const string B = 'https://b.example.org';

    private FakeFirecrawlClientFactory $fake;

    protected function setUp(): void
    {
        $this->fake = new FakeFirecrawlClientFactory();
    }

    public function testCreateRecordsTheConfigAndKeysByBaseUrl(): void
    {
        $client = $this->fake->create(['base_url' => self::A.'/', 'api_key' => 'k']);

        self::assertInstanceOf(FakeFirecrawlClient::class, $client);
        self::assertSame(self::A, $client->getBaseUrl());
        self::assertSame([['base_url' => self::A.'/', 'api_key' => 'k']], $this->fake->getCreatedConfigs());
        self::assertSame(1, $this->fake->getClientCount());
        self::assertSame(0, $this->fake->getRequestCount());
    }

    public function testUrlSpecificScriptsWinOverGlobalOnes(): void
    {
        $this->fake
            ->serve('GET', '/v2/crawl/active', ['crawls' => ['global']])
            ->serve('GET', '/v2/crawl/active', ['crawls' => ['a']], self::A.'/');

        self::assertSame(['crawls' => ['a']], $this->client(self::A)->activeCrawls());
        self::assertSame(['crawls' => ['global']], $this->client(self::B)->activeCrawls());
    }

    public function testQueueIsFifoOneShotAndBeatsServe(): void
    {
        $this->fake
            ->serve('POST', '/v2/crawl', ['id' => 'served'])
            ->queue('POST', '/v2/crawl', ['id' => 'global-1'])
            ->queue('POST', '/v2/crawl', ['id' => 'global-2'])
            ->queue('POST', '/v2/crawl', ['id' => 'a-1'], self::A);

        $a = $this->client(self::A);
        self::assertSame(['id' => 'a-1'], $a->crawl([]));
        self::assertSame(['id' => 'global-1'], $a->crawl([]));
        self::assertSame(['id' => 'global-2'], $this->client(self::B)->crawl([]));
        self::assertSame(['id' => 'served'], $a->crawl([]));
        self::assertSame(['id' => 'served'], $a->crawl([]));
    }

    public function testQueuedExceptionIsOneShot(): void
    {
        $error = new FirecrawlApiException('HTTP 500', 500, 'boom');
        $this->fake
            ->serve('GET', '/v2/crawl/job-1', ['status' => 'completed'])
            ->queueException('GET', '/v2/crawl/job-1', $error);

        $client = $this->client(self::A);
        try {
            $client->getCrawl('job-1');
            self::fail('No exception thrown.');
        } catch (FirecrawlApiException $e) {
            self::assertSame($error, $e);
        }
        self::assertSame(['status' => 'completed'], $client->getCrawl('job-1'));
    }

    public function testFailWithAndRejectCredentials(): void
    {
        $this->fake->serve('GET', '/v2/crawl/active', ['crawls' => []]);
        $this->fake->failWith(new \RuntimeException('down'), self::A);
        $this->fake->rejectCredentials(self::B);

        try {
            $this->client(self::A)->activeCrawls();
            self::fail('No exception thrown.');
        } catch (\RuntimeException $e) {
            self::assertSame('down', $e->getMessage());
        }

        try {
            $this->client(self::B)->scrape(['url' => 'https://example.org']);
            self::fail('No exception thrown.');
        } catch (FirecrawlApiException $e) {
            self::assertSame(403, $e->getStatusCode());
            self::assertSame('Forbidden', $e->getResponseBody());
        }

        self::assertSame(['crawls' => []], $this->client('https://c.example.org')->activeCrawls());
    }

    public function testUnscriptedCallIsALogicError(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('no scripted answer for POST /v2/map');

        $this->client(self::A)->map(['url' => 'https://example.org']);
    }

    public function testGetUrlIsMatchedOnPathAndQuery(): void
    {
        $this->fake->serve('GET', '/v2/crawl/job-1?skip=10', ['data' => ['page-2']]);

        self::assertSame(['data' => ['page-2']], $this->client(self::A)->getUrl('http://internal-host:3002/v2/crawl/job-1?skip=10'));
        self::assertSame(1, $this->fake->getRequestCount(self::A, 'GET', '/v2/crawl/job-1?skip=10'));
    }

    public function testRecordingAndFilters(): void
    {
        $this->fake
            ->serve('POST', '/v2/scrape', ['success' => true])
            ->serve('POST', '/v2/batch/scrape', ['id' => 'b1'])
            ->serve('GET', '/v2/batch/scrape/b1', ['status' => 'scraping']);

        $this->client(self::A)->scrape(['url' => 'https://example.org']);
        $this->client(self::B)->batchScrape(['urls' => ['https://example.org']]);
        $this->client(self::B)->getBatchScrape('b1');

        self::assertSame([
            ['baseUrl' => self::A, 'method' => 'POST', 'path' => '/v2/scrape', 'body' => ['url' => 'https://example.org']],
            ['baseUrl' => self::B, 'method' => 'POST', 'path' => '/v2/batch/scrape', 'body' => ['urls' => ['https://example.org']]],
            ['baseUrl' => self::B, 'method' => 'GET', 'path' => '/v2/batch/scrape/b1', 'body' => null],
        ], $this->fake->getRequests());
        self::assertSame(2, $this->fake->getRequestCount(self::B));
        self::assertSame(2, $this->fake->getRequestCount(self::B.'/'));
        self::assertSame(2, $this->fake->getRequestCount(null, 'post'));
        self::assertSame(1, $this->fake->getRequestCount(null, null, '/v2/scrape'));
        self::assertSame(0, $this->fake->getRequestCount(self::A, 'GET'));
        self::assertSame(3, $this->fake->getClientCount());
    }

    public function testFailedCallsAreRecordedToo(): void
    {
        try {
            $this->client(self::A)->activeCrawls();
        } catch (\LogicException) {
        }

        self::assertSame(1, $this->fake->getRequestCount(self::A, 'GET', '/v2/crawl/active'));
    }

    public function testResetForgetsEverything(): void
    {
        $this->fake->serve('GET', '/v2/crawl/active', ['crawls' => []])->failWith(new \RuntimeException('x'), self::B);
        $this->client(self::A)->activeCrawls();

        $this->fake->reset();

        self::assertSame(0, $this->fake->getRequestCount());
        self::assertSame(0, $this->fake->getClientCount());
        self::assertSame([], $this->fake->getCreatedConfigs());
        $this->expectException(\LogicException::class);
        $this->client(self::A)->activeCrawls();
    }

    private function client(string $baseUrl): FakeFirecrawlClient
    {
        return $this->fake->create(['base_url' => $baseUrl]);
    }
}
