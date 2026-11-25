<?php

// file generated with AI assistance: Claude Code - 2026-10-03 00:10:23 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\Tests\Unit\Jobs;

use Dmstr\Firecrawl\Client\FirecrawlApiException;
use Dmstr\Firecrawl\Entity\Refs\FirecrawlJob;
use Dmstr\Firecrawl\Jobs\JobSyncer;
use Dmstr\Firecrawl\Testing\FakeFirecrawlClientFactory;
use Dmstr\Firecrawl\Tests\Fixtures\FirecrawlSecrets;
use PHPUnit\Framework\TestCase;

/**
 * Kernel-free checks of refreshing a crawl or batch scrape row from its
 * instance.
 */
final class JobSyncerTest extends TestCase
{
    use InMemoryJobsTrait;

    private const string MAIN = 'https://fc.example.org';
    private const string BACKUP = 'https://fc-backup.example.org';

    private FakeFirecrawlClientFactory $fake;

    protected function setUp(): void
    {
        $this->fake = new FakeFirecrawlClientFactory();
    }

    public function testStatusAndSummaryAreCopiedFromTheRemoteCrawl(): void
    {
        $this->addConnection('main', self::MAIN);
        $backup = $this->addConnection('backup', self::BACKUP);
        $row = $this->addRow($backup, 'crawl-1')->setErrorMessage('earlier failure');
        $this->fake->serve('GET', '/v2/crawl/crawl-1', ['status' => 'completed', 'total' => 12, 'completed' => 12, 'creditsUsed' => 12, 'expiresAt' => '2026-10-04T00:00:00Z', 'data' => []], self::BACKUP);

        $result = $this->syncer()->sync($row);

        self::assertSame($row, $result);
        self::assertSame('completed', $row->getStatus());
        self::assertSame(['total' => 12, 'completed' => 12, 'credits_used' => 12], $row->getResultSummary());
        self::assertNull($row->getErrorMessage());
        self::assertSame(1, $this->flushes);
        self::assertSame(1, $this->fake->getRequestCount(self::BACKUP, 'GET', '/v2/crawl/crawl-1'), 'The row\'s own connection is used.');
    }

    public function testRowWithoutRemoteIdIsReturnedUnchanged(): void
    {
        $main = $this->addConnection('main', self::MAIN);
        $row = $this->addRow($main, null, FirecrawlJob::STATUS_COMPLETED)->setResultSummary(['markdown_length' => 3]);

        $this->syncer()->sync($row);

        self::assertSame(FirecrawlJob::STATUS_COMPLETED, $row->getStatus());
        self::assertSame(['markdown_length' => 3], $row->getResultSummary());
        self::assertSame(0, $this->fake->getClientCount());
        self::assertSame(0, $this->flushes);
    }

    public function testApiFailureSetsTheErrorAndKeepsTheStatus(): void
    {
        $main = $this->addConnection('main', self::MAIN);
        $row = $this->addRow($main, 'crawl-1')->setResultSummary(['total' => 3, 'completed' => 1, 'credits_used' => 1]);
        $this->fake->queueException('GET', '/v2/crawl/crawl-1', new FirecrawlApiException('Firecrawl request failed with HTTP 404: Job not found', 404));

        $this->syncer()->sync($row);

        self::assertSame(FirecrawlJob::STATUS_SCRAPING, $row->getStatus());
        self::assertSame(['total' => 3, 'completed' => 1, 'credits_used' => 1], $row->getResultSummary());
        self::assertSame('Firecrawl request failed with HTTP 404: Job not found', $row->getErrorMessage());
        self::assertSame(1, $this->flushes);
    }

    public function testMissingRemoteFieldsBecomeNull(): void
    {
        $main = $this->addConnection('main', self::MAIN);
        $row = $this->addRow($main, 'crawl-1');
        $this->fake->serve('GET', '/v2/crawl/crawl-1', ['success' => true]);

        $this->syncer()->sync($row);

        self::assertSame(FirecrawlJob::STATUS_SCRAPING, $row->getStatus());
        self::assertSame(['total' => null, 'completed' => null, 'credits_used' => null], $row->getResultSummary());
    }

    public function testBatchScrapeIsSyncedThroughItsOwnEndpoint(): void
    {
        $main = $this->addConnection('main', self::MAIN);
        $row = $this->addRow($main, 'batch-1')->setType(FirecrawlJob::TYPE_BATCH_SCRAPE);
        $this->fake->serve('GET', '/v2/batch/scrape/batch-1', ['success' => true, 'status' => 'completed', 'total' => 2, 'completed' => 2, 'creditsUsed' => 2, 'expiresAt' => '2026-10-03T09:00:00Z', 'data' => []]);

        $this->syncer()->sync($row);

        self::assertSame('completed', $row->getStatus());
        self::assertSame(['total' => 2, 'completed' => 2, 'credits_used' => 2], $row->getResultSummary());
        self::assertSame(1, $this->fake->getRequestCount(self::MAIN, 'GET', '/v2/batch/scrape/batch-1'));
        self::assertSame(0, $this->fake->getRequestCount(null, 'GET', '/v2/crawl/batch-1'));
    }

    public function testBatchScrapeFailureSetsTheError(): void
    {
        $main = $this->addConnection('main', self::MAIN);
        $row = $this->addRow($main, 'batch-1')->setType(FirecrawlJob::TYPE_BATCH_SCRAPE);
        $this->fake->queueException('GET', '/v2/batch/scrape/batch-1', new FirecrawlApiException('Firecrawl request failed with HTTP 404: Job not found', 404));

        $this->syncer()->sync($row);

        self::assertSame(FirecrawlJob::STATUS_SCRAPING, $row->getStatus());
        self::assertSame('Firecrawl request failed with HTTP 404: Job not found', $row->getErrorMessage());
    }

    private function syncer(): JobSyncer
    {
        return new JobSyncer($this->entityManager(), $this->fake, FirecrawlSecrets::create());
    }
}
