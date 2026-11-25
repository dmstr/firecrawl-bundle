<?php

// file generated with AI assistance: Claude Code - 2026-10-03 00:10:23 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\Tests\Unit\Jobs;

use Dmstr\Firecrawl\Client\FirecrawlApiException;
use Dmstr\Firecrawl\Entity\Refs\FirecrawlJob;
use Dmstr\Firecrawl\Jobs\JobScanner;
use Dmstr\Firecrawl\Testing\FakeFirecrawlClientFactory;
use Dmstr\Firecrawl\Tests\Fixtures\FirecrawlSecrets;
use PHPUnit\Framework\TestCase;

/**
 * Kernel-free checks of the scan of active crawls against the fake client
 * factory and an in-memory entity manager: created and unchanged rows,
 * skipped and failing connections, duplicates and unusable entries.
 */
final class JobScannerTest extends TestCase
{
    use InMemoryJobsTrait;

    private const string MAIN = 'https://fc.example.org';
    private const string BACKUP = 'https://fc-backup.example.org';
    private const string ACTIVE = '/v2/crawl/active';

    private FakeFirecrawlClientFactory $fake;

    protected function setUp(): void
    {
        $this->fake = new FakeFirecrawlClientFactory();
    }

    public function testClientGetsTheDecryptedApiKey(): void
    {
        $main = $this->addConnection('main', self::MAIN);
        // As stored since dmstr/api-configuration-bundle 0.5: the key is encrypted.
        $main->setConfigJson(FirecrawlSecrets::create()->encrypt($main->getConfigJson() + ['api_key' => 'fc-clear-key']));
        self::assertStringStartsWith('enc:v1:', $main->getConfigJson()['api_key']);
        $this->fake->serve('GET', self::ACTIVE, ['success' => true, 'crawls' => []]);

        $result = $this->scanner()->scanConnection($main);

        self::assertSame(JobScanner::STATUS_OK, $result['status']);
        self::assertSame('fc-clear-key', $this->fake->getCreatedConfigs()[0]['api_key']);
    }

    public function testMissingCrawlsAreCreatedAndExistingOnesLeftAlone(): void
    {
        $main = $this->addConnection('main', self::MAIN);
        $existing = $this->addRow($main, 'crawl-1', FirecrawlJob::STATUS_COMPLETED, 'https://old.example.org');
        $this->fake->serve('GET', self::ACTIVE, ['success' => true, 'crawls' => [
            ['id' => 'crawl-1', 'teamId' => 't', 'url' => 'https://docs.example.org', 'options' => ['limit' => 5]],
            ['id' => 'crawl-2', 'teamId' => 't', 'url' => 'https://blog.example.org', 'options' => ['limit' => 10, 'scrapeOptions' => ['formats' => ['markdown']]]],
        ]]);

        $result = $this->scanner()->scanConnection($main);

        self::assertSame(JobScanner::STATUS_OK, $result['status']);
        self::assertSame(1, $result['created']);
        self::assertSame(1, $result['unchanged']);
        self::assertNull($result['error']);
        self::assertSame((string) $main->getId(), $result['id']);
        self::assertSame('main', $result['name']);

        $rows = $this->rowsByFirecrawlId($main);
        self::assertSame(['crawl-1', 'crawl-2'], array_keys($rows));
        self::assertSame($existing, $rows['crawl-1']);
        // The scan never modifies an existing row.
        self::assertSame(FirecrawlJob::STATUS_COMPLETED, $existing->getStatus());
        self::assertSame('https://old.example.org', $existing->getUrl());

        $created = $rows['crawl-2'];
        self::assertSame(FirecrawlJob::TYPE_CRAWL, $created->getType());
        self::assertSame(FirecrawlJob::STATUS_SCRAPING, $created->getStatus());
        self::assertSame('https://blog.example.org', $created->getUrl());
        self::assertSame(['limit' => 10, 'scrapeOptions' => ['formats' => ['markdown']]], $created->getOptions());
        self::assertSame($main, $created->getApiConfiguration());
        self::assertSame(1, $this->flushes);
    }

    public function testSecondScanReportsEverythingUnchanged(): void
    {
        $main = $this->addConnection('main', self::MAIN);
        $this->fake->serve('GET', self::ACTIVE, ['success' => true, 'crawls' => [
            ['id' => 'crawl-1', 'url' => 'https://docs.example.org'],
            ['id' => 'crawl-2', 'url' => 'https://blog.example.org'],
        ]]);

        $first = $this->scanner()->scanAll();
        $second = $this->scanner()->scanAll();

        self::assertSame(2, $first['created']);
        self::assertSame(0, $second['created']);
        self::assertSame(2, $second['unchanged']);
        self::assertCount(2, $this->rowsByFirecrawlId($main));
        self::assertSame(1, $this->flushes, 'A scan without new rows does not flush.');
    }

    public function testDuplicateIdsInOneListingCreateOneRow(): void
    {
        $main = $this->addConnection('main', self::MAIN);
        $this->fake->serve('GET', self::ACTIVE, ['success' => true, 'crawls' => [
            ['id' => 'crawl-1', 'url' => 'https://docs.example.org'],
            ['id' => 'crawl-1', 'url' => 'https://other.example.org'],
        ]]);

        $result = $this->scanner()->scanConnection($main);

        self::assertSame(1, $result['created']);
        self::assertCount(1, $this->rowsByFirecrawlId($main));
        self::assertSame('https://docs.example.org', $this->rowsByFirecrawlId($main)['crawl-1']->getUrl());
        self::assertStringContainsString('listed more than once', $result['warnings'][0]);
    }

    public function testCrawlWithoutIdIsIgnoredWithAWarning(): void
    {
        $main = $this->addConnection('main', self::MAIN);
        $this->fake->serve('GET', self::ACTIVE, ['success' => true, 'crawls' => [
            ['url' => 'https://docs.example.org'],
            ['id' => '', 'url' => 'https://blank.example.org'],
            ['id' => 'crawl-2', 'url' => 'https://blog.example.org'],
        ]]);

        $result = $this->scanner()->scanConnection($main);

        self::assertSame(JobScanner::STATUS_OK, $result['status']);
        self::assertSame(1, $result['created']);
        self::assertSame(['crawl-2'], array_keys($this->rowsByFirecrawlId($main)));
        self::assertCount(2, $result['warnings']);
        self::assertStringContainsString('has no id', $result['warnings'][0]);
    }

    public function testInactiveConnectionIsSkippedWithoutACall(): void
    {
        $this->addConnection('old', self::BACKUP, active: false);

        $summary = $this->scanner()->scanAll();

        self::assertSame(1, $summary['skipped']);
        self::assertSame(0, $summary['scanned']);
        self::assertSame(JobScanner::STATUS_SKIPPED, $summary['connections'][0]['status']);
        self::assertSame(0, $this->fake->getClientCount());
    }

    public function testFailingConnectionIsReportedAndTheScanContinues(): void
    {
        $backup = $this->addConnection('backup', self::BACKUP);
        $main = $this->addConnection('main', self::MAIN);
        $this->fake->rejectCredentials(self::BACKUP);
        $this->fake->serve('GET', self::ACTIVE, ['success' => true, 'crawls' => [['id' => 'crawl-1', 'url' => 'https://docs.example.org']]], self::MAIN);

        $summary = $this->scanner()->scanAll();

        self::assertSame(['backup', 'main'], array_column($summary['connections'], 'name'));
        self::assertSame(1, $summary['failed']);
        self::assertSame(1, $summary['scanned']);
        self::assertSame(1, $summary['created']);
        self::assertSame(JobScanner::STATUS_ERROR, $summary['connections'][0]['status']);
        self::assertSame('Firecrawl request failed with HTTP 403: Forbidden', $summary['connections'][0]['error']);
        self::assertSame([], $this->rowsByFirecrawlId($backup));
        self::assertCount(1, $this->rowsByFirecrawlId($main));
    }

    public function testTransportFailureMessageIsReported(): void
    {
        $main = $this->addConnection('main', self::MAIN);
        $this->fake->failWith(new FirecrawlApiException('Firecrawl request failed: Connection refused'));

        $result = $this->scanner()->scanConnection($main);

        self::assertSame(JobScanner::STATUS_ERROR, $result['status']);
        self::assertSame('Firecrawl request failed: Connection refused', $result['error']);
    }

    public function testListingWithoutCrawlsIsAProtocolError(): void
    {
        $main = $this->addConnection('main', self::MAIN);
        $this->fake->serve('GET', self::ACTIVE, ['success' => true]);

        $result = $this->scanner()->scanConnection($main);

        self::assertSame(JobScanner::STATUS_ERROR, $result['status']);
        self::assertStringContainsString('without a crawls list', (string) $result['error']);
    }

    public function testNonFirecrawlConnectionIsAnError(): void
    {
        $docs = $this->addConnection('docs', 'https://docs.example.org/mcp', type: 'mcp');

        $summary = $this->scanner()->scan([$docs]);

        self::assertSame(1, $summary['failed']);
        self::assertSame('Connection "docs" is not a Firecrawl connection.', $summary['connections'][0]['error']);
        self::assertSame(0, $this->fake->getClientCount());
    }

    public function testScanAllOnlyCoversFirecrawlConnections(): void
    {
        $this->addConnection('docs', 'https://docs.example.org/mcp', type: 'mcp');
        $this->addConnection('main', self::MAIN);
        $this->fake->serve('GET', self::ACTIVE, ['success' => true, 'crawls' => []]);

        $summary = $this->scanner()->scanAll();

        self::assertSame(['main'], array_column($summary['connections'], 'name'));
        self::assertSame(['created' => 0, 'unchanged' => 0, 'scanned' => 1, 'skipped' => 0, 'failed' => 0], array_diff_key($summary, ['connections' => true]));
        self::assertSame(self::MAIN, $this->fake->getCreatedConfigs()[0]['base_url']);
    }

    private function scanner(): JobScanner
    {
        return new JobScanner($this->entityManager(), $this->fake, FirecrawlSecrets::create());
    }
}
