<?php

// file generated with AI assistance: Claude Code - 2026-10-03 07:43:59 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\Tests\Unit\Import;

use Dmstr\Firecrawl\Client\FirecrawlApiException;
use Dmstr\Firecrawl\Entity\Refs\FirecrawlJob;
use Dmstr\Firecrawl\Import\CrawledPage;
use Dmstr\Firecrawl\Import\RejectedPage;
use Dmstr\Firecrawl\Import\ResultReader;
use Dmstr\Firecrawl\Import\ResultReaderException;
use Dmstr\Firecrawl\Testing\FakeFirecrawlClient;
use Dmstr\Firecrawl\Testing\FakeFirecrawlClientFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Kernel-free checks of reading a crawl or batch scrape result on the fake
 * client factory: status and expiry checks on the status page, lazy
 * pagination through `next` (one request per result page, only when the
 * previous page has been consumed), the path pin of the continuation link
 * (a foreign host is never contacted, a foreign path is refused), expiry
 * between pages, and the mapping of result entries to pages or rejections.
 */
final class ResultReaderTest extends TestCase
{
    private const string BASE_URL = 'https://fc.example.org';
    private const string CRAWL = '/v2/crawl/job-1';
    private const string BATCH = '/v2/batch/scrape/batch-1';

    private FakeFirecrawlClientFactory $fake;

    protected function setUp(): void
    {
        $this->fake = new FakeFirecrawlClientFactory();
    }

    public function testThreeResultPagesAreReadLazilyOneRequestPerPage(): void
    {
        $this->fake
            ->serve('GET', self::CRAWL, self::statusPage([self::entry('a'), self::entry('b')], self::BASE_URL.self::CRAWL.'?skip=2', total: 5))
            ->serve('GET', self::CRAWL.'?skip=2', self::resultPage([self::entry('c'), self::entry('d')], self::BASE_URL.self::CRAWL.'?skip=4'))
            ->serve('GET', self::CRAWL.'?skip=4', self::resultPage([self::entry('e')]));

        $reader = ResultReader::open($this->client(), FirecrawlJob::TYPE_CRAWL, 'job-1');

        self::assertSame(1, $this->fake->getRequestCount());
        self::assertSame('completed', $reader->getStatus());
        self::assertSame(5, $reader->getTotal());
        self::assertSame(5, $reader->getCompleted());
        self::assertFalse($reader->isFinished());

        $pages = $reader->pages();
        $urls = [];
        $requestsSeen = [];
        foreach ($pages as $page) {
            self::assertInstanceOf(CrawledPage::class, $page);
            $urls[] = $page->url;
            $requestsSeen[] = $this->fake->getRequestCount();
        }

        self::assertSame(['https://example.org/a', 'https://example.org/b', 'https://example.org/c', 'https://example.org/d', 'https://example.org/e'], $urls);
        // Page 2 is fetched only after both entries of page 1 were consumed, page 3 after page 2.
        self::assertSame([1, 1, 2, 2, 3], $requestsSeen);
        self::assertSame(3, $this->fake->getRequestCount(self::BASE_URL, 'GET'));
        self::assertSame(1, $this->fake->getRequestCount(null, 'GET', self::CRAWL.'?skip=2'));
        self::assertSame(1, $this->fake->getRequestCount(null, 'GET', self::CRAWL.'?skip=4'));
        self::assertSame(3, $reader->getResultPages());
        self::assertTrue($reader->isFinished());
    }

    public function testResultPagesCountsTheStatusPageRightAfterOpen(): void
    {
        $this->fake->serve('GET', self::CRAWL, self::statusPage([self::entry('a')]));

        $reader = ResultReader::open($this->client(), FirecrawlJob::TYPE_CRAWL, 'job-1');

        // open() has fetched the status page, which counts as result page 1.
        self::assertSame(1, $reader->getResultPages());
    }

    public function testFirstEntryIsYieldedBeforeAnyFurtherRequest(): void
    {
        $this->fake
            ->serve('GET', self::CRAWL, self::statusPage([self::entry('a')], self::BASE_URL.self::CRAWL.'?skip=1'))
            ->serve('GET', self::CRAWL.'?skip=1', self::resultPage([self::entry('b')]));

        $pages = ResultReader::open($this->client(), FirecrawlJob::TYPE_CRAWL, 'job-1')->pages();
        $first = $pages->current();

        self::assertInstanceOf(CrawledPage::class, $first);
        self::assertSame('https://example.org/a', $first->url);
        self::assertSame(1, $this->fake->getRequestCount());

        $pages->next();
        self::assertSame(2, $this->fake->getRequestCount());
    }

    public function testForeignHostOfTheContinuationLinkIsNeverContacted(): void
    {
        $this->fake
            ->serve('GET', self::CRAWL, self::statusPage([self::entry('a')], 'http://other-host:3002'.self::CRAWL.'?skip=10'))
            ->serve('GET', self::CRAWL.'?skip=10', self::resultPage([self::entry('b')]));

        $urls = $this->urls(ResultReader::open($this->client(), FirecrawlJob::TYPE_CRAWL, 'job-1'));

        self::assertSame(['https://example.org/a', 'https://example.org/b'], $urls);
        self::assertSame(2, $this->fake->getRequestCount(self::BASE_URL));
        self::assertSame(0, $this->fake->getRequestCount('http://other-host:3002'));
        self::assertSame([self::BASE_URL], array_values(array_unique(array_column($this->fake->getRequests(), 'baseUrl'))));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function foreignLinks(): iterable
    {
        yield 'other job id' => [self::BASE_URL.'/v2/crawl/other-job?skip=10'];
        yield 'other endpoint' => [self::BASE_URL.'/v2/map'];
        yield 'batch endpoint of the same id' => [self::BASE_URL.'/v2/batch/scrape/job-1?skip=10'];
        yield 'sub path' => [self::BASE_URL.self::CRAWL.'/errors'];
        yield 'no path' => ['https://fc.example.org?skip=10'];
    }

    #[DataProvider('foreignLinks')]
    public function testForeignPathOfTheContinuationLinkIsRefused(string $next): void
    {
        $this->fake->serve('GET', self::CRAWL, self::statusPage([self::entry('a')], $next));
        $reader = ResultReader::open($this->client(), FirecrawlJob::TYPE_CRAWL, 'job-1');

        $read = [];
        try {
            foreach ($reader->pages() as $page) {
                $read[] = $page;
            }
            self::fail('Expected a ResultReaderException.');
        } catch (ResultReaderException $e) {
            self::assertStringContainsString('does not belong to this job', $e->getMessage());
        }

        // The entries of page 1 were yielded; the link was refused before any request.
        self::assertCount(1, $read);
        self::assertSame(1, $this->fake->getRequestCount());
        self::assertFalse($reader->isFinished());
    }

    public function testRepeatedContinuationLinkIsRefused(): void
    {
        $next = self::BASE_URL.self::CRAWL.'?skip=1';
        $this->fake
            ->serve('GET', self::CRAWL, self::statusPage([self::entry('a')], $next))
            ->serve('GET', self::CRAWL.'?skip=1', self::resultPage([self::entry('b')], $next));

        $this->expectException(ResultReaderException::class);
        $this->expectExceptionMessage('repeats an earlier one');

        try {
            $this->urls(ResultReader::open($this->client(), FirecrawlJob::TYPE_CRAWL, 'job-1'));
        } finally {
            self::assertSame(2, $this->fake->getRequestCount());
        }
    }

    public function testStatusOtherThanCompletedIsRefused(): void
    {
        $this->fake->serve('GET', self::CRAWL, ['success' => true, 'status' => 'scraping', 'total' => 5, 'completed' => 2, 'data' => [self::entry('a')]]);

        try {
            ResultReader::open($this->client(), FirecrawlJob::TYPE_CRAWL, 'job-1');
            self::fail('Expected a ResultReaderException.');
        } catch (ResultReaderException $e) {
            self::assertStringContainsString('is not completed (remote status: scraping)', $e->getMessage());
        }

        self::assertSame(1, $this->fake->getRequestCount());
    }

    public function testMissingStatusIsRefused(): void
    {
        $this->fake->serve('GET', self::CRAWL, ['success' => true, 'data' => []]);

        $this->expectException(ResultReaderException::class);
        $this->expectExceptionMessage('remote status: unknown');

        ResultReader::open($this->client(), FirecrawlJob::TYPE_CRAWL, 'job-1');
    }

    public function testPastExpiryIsRefusedWithoutFetchingAResultPage(): void
    {
        $this->fake
            ->serve('GET', self::CRAWL, self::statusPage([self::entry('a')], self::BASE_URL.self::CRAWL.'?skip=1', expiresAt: '2026-10-03T08:00:00.000Z'))
            ->serve('GET', self::CRAWL.'?skip=1', self::resultPage([self::entry('b')]));

        try {
            ResultReader::open($this->client(), FirecrawlJob::TYPE_CRAWL, 'job-1', new \DateTimeImmutable('2026-10-03T09:00:00Z'));
            self::fail('Expected a ResultReaderException.');
        } catch (ResultReaderException $e) {
            self::assertStringContainsString('expired at 2026-10-03T08:00:00', $e->getMessage());
        }

        self::assertSame(1, $this->fake->getRequestCount());
    }

    public function testFutureExpiryIsAccepted(): void
    {
        $this->fake->serve('GET', self::CRAWL, self::statusPage([self::entry('a')], null, expiresAt: '2026-10-04T08:00:00Z'));

        $reader = ResultReader::open($this->client(), FirecrawlJob::TYPE_CRAWL, 'job-1', new \DateTimeImmutable('2026-10-03T09:00:00Z'));

        self::assertEquals(new \DateTimeImmutable('2026-10-04T08:00:00Z'), $reader->getExpiresAt());
        self::assertSame(['https://example.org/a'], $this->urls($reader));
    }

    public function testNotFoundOnTheStatusPageIsAnExpiredResult(): void
    {
        $this->fake->queueException('GET', self::CRAWL, new FirecrawlApiException('Firecrawl request failed with HTTP 404: Job not found', 404, '{"success":false,"error":"Job not found"}'));

        $this->expectException(ResultReaderException::class);
        $this->expectExceptionMessage('expired or is unknown on the instance (result page 1, HTTP 404)');

        ResultReader::open($this->client(), FirecrawlJob::TYPE_CRAWL, 'job-1');
    }

    public function testNotFoundOnPageTwoNamesThePage(): void
    {
        $this->fake
            ->serve('GET', self::CRAWL, self::statusPage([self::entry('a')], self::BASE_URL.self::CRAWL.'?skip=1'))
            ->queueException('GET', self::CRAWL.'?skip=1', new FirecrawlApiException('Firecrawl request failed with HTTP 404: Job not found', 404));

        $reader = ResultReader::open($this->client(), FirecrawlJob::TYPE_CRAWL, 'job-1');
        $read = [];
        try {
            foreach ($reader->pages() as $page) {
                $read[] = $page;
            }
            self::fail('Expected a ResultReaderException.');
        } catch (ResultReaderException $e) {
            self::assertStringContainsString('result page 2', $e->getMessage());
            self::assertStringContainsString('expired or is unknown on the instance', $e->getMessage());
            self::assertInstanceOf(FirecrawlApiException::class, $e->getPrevious());
        }

        self::assertCount(1, $read);
        self::assertFalse($reader->isFinished());
    }

    public function testSuccessFalseIsTreatedAsExpired(): void
    {
        $this->fake->serve('GET', self::CRAWL, ['success' => false, 'error' => 'Job expired']);

        $this->expectException(ResultReaderException::class);
        $this->expectExceptionMessage('result page 1, success: false: Job expired');

        ResultReader::open($this->client(), FirecrawlJob::TYPE_CRAWL, 'job-1');
    }

    public function testSuccessFalseOnPageTwoIsTreatedAsExpired(): void
    {
        $this->fake
            ->serve('GET', self::CRAWL, self::statusPage([self::entry('a')], self::BASE_URL.self::CRAWL.'?skip=1'))
            ->serve('GET', self::CRAWL.'?skip=1', ['success' => false]);

        $this->expectException(ResultReaderException::class);
        $this->expectExceptionMessage('result page 2, success: false');

        $this->urls(ResultReader::open($this->client(), FirecrawlJob::TYPE_CRAWL, 'job-1'));
    }

    public function testOtherHttpErrorsAreNotWrapped(): void
    {
        $error = new FirecrawlApiException('Firecrawl request failed with HTTP 502: Bad Gateway', 502);
        $this->fake
            ->serve('GET', self::CRAWL, self::statusPage([self::entry('a')], self::BASE_URL.self::CRAWL.'?skip=1'))
            ->queueException('GET', self::CRAWL.'?skip=1', $error);

        try {
            $this->urls(ResultReader::open($this->client(), FirecrawlJob::TYPE_CRAWL, 'job-1'));
            self::fail('Expected a FirecrawlApiException.');
        } catch (FirecrawlApiException $e) {
            self::assertSame($error, $e);
        }
    }

    public function testEntriesWithoutMarkdownOrUrlAreRejectedPages(): void
    {
        $this->fake->serve('GET', self::CRAWL, self::statusPage([
            ['metadata' => ['sourceURL' => 'https://example.org/no-markdown']],
            ['markdown' => '   ', 'metadata' => ['sourceURL' => 'https://example.org/blank']],
            ['markdown' => '# No URL', 'metadata' => ['title' => 'No URL']],
            ['markdown' => '# Bad URL', 'metadata' => ['sourceURL' => 'ftp://example.org/file']],
            ['markdown' => '# Long', 'metadata' => ['sourceURL' => 'https://example.org/'.str_repeat('a', 2100)]],
            'not an entry',
            self::entry('ok'),
        ]));

        $pages = iterator_to_array(ResultReader::open($this->client(), FirecrawlJob::TYPE_CRAWL, 'job-1')->pages(), false);

        self::assertCount(7, $pages);
        [$noMarkdown, $blank, $noUrl, $badUrl, $long, $garbage, $ok] = $pages;

        self::assertInstanceOf(RejectedPage::class, $noMarkdown);
        self::assertSame('https://example.org/no-markdown', $noMarkdown->url);
        self::assertStringContainsString('no Markdown content', $noMarkdown->reason);
        self::assertInstanceOf(RejectedPage::class, $blank);
        self::assertSame('https://example.org/blank', $blank->url);

        self::assertInstanceOf(RejectedPage::class, $noUrl);
        self::assertNull($noUrl->url);
        self::assertStringContainsString('no source URL', $noUrl->reason);

        self::assertInstanceOf(RejectedPage::class, $badUrl);
        self::assertSame('ftp://example.org/file', $badUrl->url);
        self::assertStringContainsString('not an absolute http(s) URL', $badUrl->reason);

        self::assertInstanceOf(RejectedPage::class, $long);
        self::assertStringContainsString('longer than 2048 characters', $long->reason);
        self::assertLessThanOrEqual(210, \strlen((string) $long->url));

        self::assertInstanceOf(RejectedPage::class, $garbage);
        self::assertInstanceOf(CrawledPage::class, $ok);
    }

    public function testSourceUrlIsPreferredOverUrlAndNormalised(): void
    {
        $page = ResultReader::page(['markdown' => 'Body', 'metadata' => ['sourceURL' => 'HTTPS://Example.org/Source/#top', 'url' => 'https://example.org/redirected']]);

        self::assertInstanceOf(CrawledPage::class, $page);
        self::assertSame('https://example.org/Source', $page->url);

        $fallback = ResultReader::page(['markdown' => 'Body', 'metadata' => ['sourceURL' => '  ', 'url' => 'https://example.org/redirected/']]);
        self::assertInstanceOf(CrawledPage::class, $fallback);
        self::assertSame('https://example.org/redirected', $fallback->url);
    }

    public function testPageCarriesNormalisedMarkdownTitleAndStatusCode(): void
    {
        $page = ResultReader::page(['markdown' => "Title\r\n=====\r\nBody", 'metadata' => ['sourceURL' => 'https://example.org/a', 'title' => '  Page title ', 'statusCode' => 200]]);

        self::assertInstanceOf(CrawledPage::class, $page);
        self::assertSame("# Title\nBody", $page->markdown);
        self::assertSame('Page title', $page->title);
        self::assertSame(200, $page->statusCode);

        $listTitle = ResultReader::page(['markdown' => 'Body', 'metadata' => ['url' => 'https://example.org/b', 'title' => [1, 'First', 'Second'], 'statusCode' => '404']]);
        self::assertInstanceOf(CrawledPage::class, $listTitle);
        self::assertSame('First', $listTitle->title);
        self::assertSame(404, $listTitle->statusCode);

        $bare = ResultReader::page(['markdown' => 'Body', 'metadata' => ['url' => 'https://example.org/c', 'title' => '', 'statusCode' => 'n/a']]);
        self::assertInstanceOf(CrawledPage::class, $bare);
        self::assertNull($bare->title);
        self::assertNull($bare->statusCode);
    }

    public function testBatchScrapeIsReadThroughItsOwnEndpoint(): void
    {
        $this->fake
            ->serve('GET', self::BATCH, self::statusPage([self::entry('a')], 'http://internal:3002'.self::BATCH.'?skip=1'))
            ->serve('GET', self::BATCH.'?skip=1', self::resultPage([self::entry('b')]));

        $reader = ResultReader::open($this->client(), FirecrawlJob::TYPE_BATCH_SCRAPE, 'batch-1');

        self::assertSame(['https://example.org/a', 'https://example.org/b'], $this->urls($reader));
        self::assertSame(1, $this->fake->getRequestCount(self::BASE_URL, 'GET', self::BATCH));
        self::assertSame(1, $this->fake->getRequestCount(self::BASE_URL, 'GET', self::BATCH.'?skip=1'));
        self::assertSame(0, $this->fake->getRequestCount(null, 'GET', '/v2/crawl/batch-1'));
    }

    public function testBatchScrapeRefusesACrawlContinuationLink(): void
    {
        $this->fake->serve('GET', self::BATCH, self::statusPage([self::entry('a')], self::BASE_URL.'/v2/crawl/batch-1?skip=1'));

        $this->expectException(ResultReaderException::class);
        $this->expectExceptionMessage('expected the path /v2/batch/scrape/batch-1');

        $this->urls(ResultReader::open($this->client(), FirecrawlJob::TYPE_BATCH_SCRAPE, 'batch-1'));
    }

    public function testEmptyResultYieldsNothing(): void
    {
        $this->fake->serve('GET', self::CRAWL, self::statusPage([]));

        $reader = ResultReader::open($this->client(), FirecrawlJob::TYPE_CRAWL, 'job-1');

        self::assertSame([], $this->urls($reader));
        self::assertTrue($reader->isFinished());
    }

    public function testPagesCanBeReadOnlyOnce(): void
    {
        $this->fake->serve('GET', self::CRAWL, self::statusPage([self::entry('a')]));
        $reader = ResultReader::open($this->client(), FirecrawlJob::TYPE_CRAWL, 'job-1');
        $this->urls($reader);

        $this->expectException(\LogicException::class);
        $reader->pages()->current();
    }

    public function testOnlyCrawlsAndBatchScrapesCanBeRead(): void
    {
        try {
            ResultReader::open($this->client(), FirecrawlJob::TYPE_SCRAPE, 'job-1');
            self::fail('Expected an InvalidArgumentException.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('"scrape"', $e->getMessage());
        }

        try {
            ResultReader::open($this->client(), FirecrawlJob::TYPE_CRAWL, '');
            self::fail('Expected an InvalidArgumentException.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('no remote id', $e->getMessage());
        }

        self::assertSame(0, $this->fake->getRequestCount());
    }

    private function client(): FakeFirecrawlClient
    {
        return $this->fake->create(['firecrawl' => true, 'type' => 'firecrawl', 'endpoint_type' => 'rest', 'base_url' => self::BASE_URL]);
    }

    /**
     * @return list<string> the URLs of every crawled page (rejections are skipped)
     */
    private function urls(ResultReader $reader): array
    {
        $urls = [];
        foreach ($reader->pages() as $page) {
            if ($page instanceof CrawledPage) {
                $urls[] = $page->url;
            }
        }

        return $urls;
    }

    /**
     * @return array<string, mixed> a result entry of https://example.org/<slug>
     */
    private static function entry(string $slug): array
    {
        return ['markdown' => '# Page '.$slug."\n\nText of ".$slug.'.', 'metadata' => ['sourceURL' => 'https://example.org/'.$slug, 'title' => 'Page '.$slug, 'statusCode' => 200]];
    }

    /**
     * @param list<mixed> $data
     *
     * @return array<string, mixed> a completed status page (result page 1)
     */
    private static function statusPage(array $data, ?string $next = null, int $total = 1, ?string $expiresAt = '2099-01-01T00:00:00Z'): array
    {
        $response = ['success' => true, 'status' => 'completed', 'total' => $total, 'completed' => $total, 'creditsUsed' => $total, 'expiresAt' => $expiresAt, 'data' => $data];
        if (null !== $next) {
            $response['next'] = $next;
        }

        return $response;
    }

    /**
     * @param list<mixed> $data
     *
     * @return array<string, mixed> a further result page
     */
    private static function resultPage(array $data, ?string $next = null): array
    {
        return self::statusPage($data, $next);
    }
}
