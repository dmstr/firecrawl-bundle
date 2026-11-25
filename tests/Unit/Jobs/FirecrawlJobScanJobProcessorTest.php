<?php

// file generated with AI assistance: Claude Code - 2026-10-03 00:10:23 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\Tests\Unit\Jobs;

use Dmstr\Firecrawl\JobProcessor\FirecrawlJobScanJobProcessor;
use Dmstr\Firecrawl\Jobs\JobScanner;
use Dmstr\Firecrawl\Testing\FakeFirecrawlClientFactory;
use Dmstr\Firecrawl\Tests\Fixtures\FirecrawlSecrets;
use Dmstr\SymfonyJobQueue\Entity\Job;
use PHPUnit\Framework\TestCase;

/**
 * Kernel-free checks of the `firecrawl_job_scan` job processor: job type,
 * dispatching to the scanner and the job result / failure contract.
 */
final class FirecrawlJobScanJobProcessorTest extends TestCase
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

    public function testSupportsOnlyTheScanJobType(): void
    {
        $processor = $this->processor();

        self::assertTrue($processor->supports('firecrawl_job_scan'));
        self::assertFalse($processor->supports('mcp_tool_scan'));
    }

    public function testEmptyInputScansAllConnectionsAndStoresTheSummary(): void
    {
        $this->addConnection('main', self::MAIN);
        $this->addConnection('backup', self::BACKUP);
        $this->fake->serve('GET', self::ACTIVE, ['success' => true, 'crawls' => [['id' => 'crawl-1', 'url' => 'https://docs.example.org']]]);
        $job = self::job([]);

        $this->processor()->process($job);

        $summary = $job->getResultData();
        self::assertSame(2, $summary['scanned']);
        self::assertSame(2, $summary['created']);
        self::assertSame(['backup', 'main'], array_column($summary['connections'], 'name'));
        self::assertCount(2, $this->rows);
    }

    public function testConnectionInputScansOnlyThatConnection(): void
    {
        $main = $this->addConnection('main', self::MAIN);
        $this->addConnection('backup', self::BACKUP);
        $this->fake->serve('GET', self::ACTIVE, ['success' => true, 'crawls' => []]);
        $job = self::job(['apiConfiguration' => (string) $main->getId()]);

        $this->processor()->process($job);

        self::assertSame(['main'], array_column($job->getResultData()['connections'], 'name'));
        self::assertSame(0, $this->fake->getRequestCount(self::BACKUP));
    }

    public function testUnknownConnectionFailsTheJob(): void
    {
        $job = self::job(['apiConfiguration' => '0199a1b2-0000-7000-8000-000000000000']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Firecrawl connection "0199a1b2-0000-7000-8000-000000000000" does not exist (anymore).');

        $this->processor()->process($job);
    }

    public function testPartialFailureCompletesWithErrorsInTheSummary(): void
    {
        $this->addConnection('backup', self::BACKUP);
        $this->addConnection('main', self::MAIN);
        $this->fake->rejectCredentials(self::BACKUP);
        $this->fake->serve('GET', self::ACTIVE, ['success' => true, 'crawls' => []], self::MAIN);
        $job = self::job([]);

        $this->processor()->process($job);

        self::assertSame(1, $job->getResultData()['failed']);
        self::assertSame(1, $job->getResultData()['scanned']);
        self::assertSame(JobScanner::STATUS_ERROR, $job->getResultData()['connections'][0]['status']);
    }

    public function testTotalFailureThrowsAndKeepsTheSummary(): void
    {
        $this->addConnection('main', self::MAIN);
        $this->addConnection('backup', self::BACKUP);
        $this->fake->rejectCredentials();
        $job = self::job([]);

        try {
            $this->processor()->process($job);
            self::fail('Expected the job to fail.');
        } catch (\RuntimeException $e) {
            self::assertSame('Firecrawl job scan failed. backup: Firecrawl request failed with HTTP 403: Forbidden; main: Firecrawl request failed with HTTP 403: Forbidden', $e->getMessage());
        }

        self::assertSame(2, $job->getResultData()['failed']);
        self::assertSame(0, $job->getResultData()['scanned']);
    }

    public function testOnlyInactiveConnectionsIsNoFailure(): void
    {
        $this->addConnection('old', self::MAIN, active: false);
        $job = self::job([]);

        $this->processor()->process($job);

        self::assertSame(1, $job->getResultData()['skipped']);
    }

    private function processor(): FirecrawlJobScanJobProcessor
    {
        $entityManager = $this->entityManager();

        return new FirecrawlJobScanJobProcessor(new JobScanner($entityManager, $this->fake, FirecrawlSecrets::create()), $entityManager);
    }

    /**
     * @param array<string, mixed> $input
     */
    private static function job(array $input): Job
    {
        return (new Job())->setType('firecrawl_job_scan')->setInputData($input)->setStatus('running');
    }
}
