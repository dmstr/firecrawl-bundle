<?php

// file generated with AI assistance: Claude Code - 2026-10-03 00:10:23 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\Tests\Unit\Jobs;

use Dmstr\Firecrawl\Client\FirecrawlApiException;
use Dmstr\Firecrawl\Entity\Refs\FirecrawlJob;
use Dmstr\Firecrawl\Jobs\JobStarter;
use Dmstr\Firecrawl\Testing\FakeFirecrawlClientFactory;
use Dmstr\Firecrawl\Tests\Fixtures\FirecrawlSecrets;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;

/**
 * Kernel-free checks of starting jobs against the fake client factory:
 * synchronous scrape, asynchronous crawl and batch scrape, API failures,
 * the request body and the log line.
 */
final class JobStarterTest extends TestCase
{
    use InMemoryJobsTrait;

    private const string MAIN = 'https://fc.example.org';

    private FakeFirecrawlClientFactory $fake;

    /** @var \ArrayObject<int, array{level: mixed, message: string, context: array<string, mixed>}>|null */
    private ?\ArrayObject $logSink = null;

    protected function setUp(): void
    {
        $this->fake = new FakeFirecrawlClientFactory();
    }

    public function testScrapeCompletesSynchronouslyWithASummary(): void
    {
        $main = $this->addConnection('main', self::MAIN);
        $this->fake->queue('POST', '/v2/scrape', ['success' => true, 'data' => ['markdown' => '# Hello', 'metadata' => ['title' => 'Hello', 'statusCode' => 200]]]);

        $job = $this->starter()->start(FirecrawlJob::TYPE_SCRAPE, 'https://example.org', ['formats' => ['markdown']], $main);

        self::assertSame(FirecrawlJob::STATUS_COMPLETED, $job->getStatus());
        self::assertSame(['metadata' => ['title' => 'Hello', 'statusCode' => 200], 'markdown_length' => 7], $job->getResultSummary());
        self::assertNull($job->getFirecrawlId());
        self::assertNull($job->getErrorMessage());
        self::assertSame(['formats' => ['markdown']], $job->getOptions());
        self::assertSame($main, $job->getApiConfiguration());
        self::assertSame([$job], array_values($this->rows));
        self::assertSame(1, $this->flushes);
        self::assertSame(['url' => 'https://example.org', 'formats' => ['markdown']], $this->fake->getRequests(self::MAIN, 'POST', '/v2/scrape')[0]['body']);
    }

    public function testCrawlStoresTheRemoteIdAndIsScraping(): void
    {
        $main = $this->addConnection('main', self::MAIN);
        $this->fake->queue('POST', '/v2/crawl', ['success' => true, 'id' => 'crawl-1', 'url' => self::MAIN.'/v2/crawl/crawl-1']);

        $job = $this->starter()->start(FirecrawlJob::TYPE_CRAWL, 'https://example.org', null, $main);

        self::assertSame(FirecrawlJob::STATUS_SCRAPING, $job->getStatus());
        self::assertSame('crawl-1', $job->getFirecrawlId());
        self::assertNull($job->getResultSummary());
        self::assertNull($job->getOptions());
        self::assertSame(['url' => 'https://example.org'], $this->fake->getRequests(null, 'POST', '/v2/crawl')[0]['body']);
    }

    public function testOptionsAreMergedUnderTheUrl(): void
    {
        $main = $this->addConnection('main', self::MAIN);
        $this->fake->queue('POST', '/v2/crawl', ['success' => true, 'id' => 'crawl-1']);

        $this->starter()->start(FirecrawlJob::TYPE_CRAWL, 'https://example.org', ['url' => 'https://evil.example.org', 'limit' => 10], $main);

        self::assertSame(['url' => 'https://example.org', 'limit' => 10], $this->fake->getRequests(null, 'POST', '/v2/crawl')[0]['body']);
    }

    public function testApiFailureIsStoredAsAFailedRow(): void
    {
        $main = $this->addConnection('main', self::MAIN);
        $this->fake->queueException('POST', '/v2/crawl', new FirecrawlApiException('Firecrawl request failed with HTTP 500: boom', 500, 'boom'));

        $job = $this->starter()->start(FirecrawlJob::TYPE_CRAWL, 'https://example.org', null, $main);

        self::assertSame(FirecrawlJob::STATUS_FAILED, $job->getStatus());
        self::assertSame('Firecrawl request failed with HTTP 500: boom', $job->getErrorMessage());
        self::assertNull($job->getFirecrawlId());
        self::assertSame([$job], array_values($this->rows));
        self::assertSame(1, $this->flushes);
    }

    public function testCrawlAnswerWithoutIdFails(): void
    {
        $main = $this->addConnection('main', self::MAIN);
        $this->fake->queue('POST', '/v2/crawl', ['success' => true]);

        $job = $this->starter()->start(FirecrawlJob::TYPE_CRAWL, 'https://example.org', null, $main);

        self::assertSame(FirecrawlJob::STATUS_FAILED, $job->getStatus());
        self::assertStringContainsString('without a job id', (string) $job->getErrorMessage());
    }

    public function testBatchScrapeStoresTheUrlListAndIsScraping(): void
    {
        $main = $this->addConnection('main', self::MAIN);
        $this->fake->queue('POST', '/v2/batch/scrape', ['success' => true, 'id' => 'batch-1', 'url' => self::MAIN.'/v2/batch/scrape/batch-1']);
        $urls = ['https://example.org/a', 'https://example.org/b'];

        $job = $this->starter()->start(FirecrawlJob::TYPE_BATCH_SCRAPE, '', ['formats' => ['markdown'], 'urls' => ['https://evil.example.org']], $main, $urls);

        self::assertSame(FirecrawlJob::TYPE_BATCH_SCRAPE, $job->getType());
        self::assertSame(FirecrawlJob::STATUS_SCRAPING, $job->getStatus());
        self::assertSame('batch-1', $job->getFirecrawlId());
        self::assertSame('https://example.org/a', $job->getUrl(), 'The url column holds the first URL.');
        self::assertSame(['urls' => $urls, 'formats' => ['markdown']], $job->getOptions());
        self::assertNull($job->getResultSummary());
        self::assertNull($job->getErrorMessage());
        self::assertSame([$job], array_values($this->rows));
        self::assertSame(1, $this->flushes);
        // The URL list wins over `urls` inside the options; nothing goes to /v2/crawl or /v2/scrape.
        self::assertSame([['baseUrl' => self::MAIN, 'method' => 'POST', 'path' => '/v2/batch/scrape', 'body' => ['urls' => $urls, 'formats' => ['markdown']]]], $this->fake->getRequests());
    }

    public function testBatchScrapeIgnoresTheUrlArgument(): void
    {
        $main = $this->addConnection('main', self::MAIN);
        $this->fake->queue('POST', '/v2/batch/scrape', ['success' => true, 'id' => 'batch-1']);

        $job = $this->starter()->start(FirecrawlJob::TYPE_BATCH_SCRAPE, 'https://ignored.example.org', null, $main, ['https://example.org/only']);

        self::assertSame('https://example.org/only', $job->getUrl());
        self::assertSame(['urls' => ['https://example.org/only']], $job->getOptions());
        self::assertSame(['urls' => ['https://example.org/only']], $this->fake->getRequests(null, 'POST', '/v2/batch/scrape')[0]['body']);
    }

    public function testBatchScrapeFailureIsStoredAsAFailedRow(): void
    {
        $main = $this->addConnection('main', self::MAIN);
        $this->fake->queueException('POST', '/v2/batch/scrape', new FirecrawlApiException('Firecrawl request failed with HTTP 402: Payment required', 402));

        $job = $this->starter()->start(FirecrawlJob::TYPE_BATCH_SCRAPE, '', null, $main, ['https://example.org/a', 'https://example.org/b']);

        self::assertSame(FirecrawlJob::STATUS_FAILED, $job->getStatus());
        self::assertSame('Firecrawl request failed with HTTP 402: Payment required', $job->getErrorMessage());
        self::assertNull($job->getFirecrawlId());
        self::assertSame('https://example.org/a', $job->getUrl());
        self::assertSame([$job], array_values($this->rows));
    }

    public function testBatchScrapeAnswerWithoutIdFails(): void
    {
        $main = $this->addConnection('main', self::MAIN);
        $this->fake->queue('POST', '/v2/batch/scrape', ['success' => true]);

        $job = $this->starter()->start(FirecrawlJob::TYPE_BATCH_SCRAPE, '', null, $main, ['https://example.org/a']);

        self::assertSame(FirecrawlJob::STATUS_FAILED, $job->getStatus());
        self::assertStringContainsString('batch scrape request without a job id', (string) $job->getErrorMessage());
    }

    public function testBatchScrapeWithoutUrlsIsRejectedBeforeAnything(): void
    {
        $main = $this->addConnection('main', self::MAIN);

        try {
            $this->starter()->start(FirecrawlJob::TYPE_BATCH_SCRAPE, 'https://example.org', null, $main);
            self::fail('Expected an exception.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('at least one URL', $e->getMessage());
        }

        self::assertSame([], $this->rows);
        self::assertSame(0, $this->fake->getClientCount());
    }

    public function testBatchScrapeLogsTheUrlCount(): void
    {
        $main = $this->addConnection('main', self::MAIN);
        $this->fake->queue('POST', '/v2/batch/scrape', ['success' => true, 'id' => 'batch-1']);

        $this->starter()->start(FirecrawlJob::TYPE_BATCH_SCRAPE, '', null, $main, ['https://example.org/a', 'https://example.org/b', 'https://example.org/c']);

        self::assertCount(1, $this->logs());
        self::assertSame('batch_scrape', $this->logs()[0]['context']['type']);
        self::assertSame('https://example.org/a', $this->logs()[0]['context']['url']);
        self::assertSame(3, $this->logs()[0]['context']['urls']);
        self::assertSame('batch-1', $this->logs()[0]['context']['firecrawl_id']);
    }

    public function testCrawlIgnoresTheUrlList(): void
    {
        $main = $this->addConnection('main', self::MAIN);
        $this->fake->queue('POST', '/v2/crawl', ['success' => true, 'id' => 'crawl-1']);

        $job = $this->starter()->start(FirecrawlJob::TYPE_CRAWL, 'https://example.org', null, $main, ['https://other.example.org']);

        self::assertSame('https://example.org', $job->getUrl());
        self::assertNull($job->getOptions());
        self::assertSame(['url' => 'https://example.org'], $this->fake->getRequests(null, 'POST', '/v2/crawl')[0]['body']);
        self::assertSame(0, $this->fake->getRequestCount(null, 'POST', '/v2/batch/scrape'));
    }

    public function testUnknownTypeIsRejectedBeforeAnything(): void
    {
        $main = $this->addConnection('main', self::MAIN);

        try {
            $this->starter()->start('map', 'https://example.org', null, $main);
            self::fail('Expected an exception.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('Unknown Firecrawl job type "map"', $e->getMessage());
        }

        self::assertSame([], $this->rows);
        self::assertSame(0, $this->fake->getClientCount());
    }

    public function testOneInfoLineIsLoggedWithTheActor(): void
    {
        $main = $this->addConnection('main', self::MAIN);
        $this->fake->queue('POST', '/v2/crawl', ['success' => true, 'id' => 'crawl-1']);
        $tokenStorage = new TokenStorage();
        $tokenStorage->setToken(new UsernamePasswordToken(new InMemoryUser('alice', null, ['ROLE_ADMIN']), 'api', ['ROLE_ADMIN']));

        $job = $this->starter($tokenStorage)->start(FirecrawlJob::TYPE_CRAWL, 'https://example.org', null, $main);

        self::assertCount(1, $this->logs());
        self::assertSame('info', $this->logs()[0]['level']);
        self::assertSame([
            'type' => 'crawl',
            'url' => 'https://example.org',
            'connection' => 'main',
            'connection_id' => (string) $main->getId(),
            'actor' => 'alice',
            'status' => 'scraping',
            'job_id' => (string) $job->getId(),
            'firecrawl_id' => 'crawl-1',
        ], $this->logs()[0]['context']);
    }

    public function testActorIsConsoleWithoutAToken(): void
    {
        $main = $this->addConnection('main', self::MAIN);
        $this->fake->queue('POST', '/v2/scrape', ['success' => true, 'data' => []]);

        $this->starter()->start(FirecrawlJob::TYPE_SCRAPE, 'https://example.org', null, $main);

        self::assertSame('console', $this->logs()[0]['context']['actor']);
    }

    /**
     * @return list<array{level: mixed, message: string, context: array<string, mixed>}>
     */
    private function logs(): array
    {
        return null === $this->logSink ? [] : array_values($this->logSink->getArrayCopy());
    }

    private function starter(?TokenStorage $tokenStorage = null): JobStarter
    {
        $logs = new \ArrayObject();
        $this->logSink = $logs;
        $logger = new class($logs) extends AbstractLogger {
            /** @param \ArrayObject<int, array<string, mixed>> $records */
            public function __construct(private readonly \ArrayObject $records)
            {
            }

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records->append(['level' => $level, 'message' => (string) $message, 'context' => $context]);
            }
        };

        return new JobStarter($this->entityManager(), $this->fake, FirecrawlSecrets::create(), $logger, $tokenStorage);
    }
}
