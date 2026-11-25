<?php

// file generated with AI assistance: Claude Code - 2026-10-03 00:10:23 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\Tests\Unit\Jobs;

use Dmstr\ApiPlatformUtils\Service\UuidResolver;
use Dmstr\Firecrawl\Command\JobsCommand;
use Dmstr\Firecrawl\Command\JobsScanCommand;
use Dmstr\Firecrawl\Command\JobsStartCommand;
use Dmstr\Firecrawl\Command\JobsSyncCommand;
use Dmstr\Firecrawl\Entity\Refs\FirecrawlJob;
use Dmstr\Firecrawl\Jobs\JobScanner;
use Dmstr\Firecrawl\Jobs\JobStarter;
use Dmstr\Firecrawl\Jobs\JobSyncer;
use Dmstr\Firecrawl\Testing\FakeFirecrawlClientFactory;
use Dmstr\Firecrawl\Tests\Fixtures\FirecrawlSecrets;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Kernel-free checks of the console twins `firecrawl:jobs`,
 * `firecrawl:jobs:start`, `firecrawl:jobs:sync` and `firecrawl:jobs:scan`
 * with the fake client factory, an in-memory entity manager and a stubbed
 * UuidResolver. `firecrawl:jobs:import` is covered by
 * {@see \Dmstr\Firecrawl\Tests\Unit\Import\JobsImportCommandTest}.
 */
final class JobsCommandsTest extends TestCase
{
    use InMemoryJobsTrait;

    private const string MAIN = 'https://fc.example.org';
    private const string BACKUP = 'https://fc-backup.example.org';

    private FakeFirecrawlClientFactory $fake;

    protected function setUp(): void
    {
        $this->fake = new FakeFirecrawlClientFactory();
    }

    public function testListFiltersByStatusTypeAndConnection(): void
    {
        $main = $this->addConnection('main', self::MAIN);
        $backup = $this->addConnection('backup', self::BACKUP);
        $this->addRow($main, 'crawl-main', FirecrawlJob::STATUS_SCRAPING, 'https://main.example.org');
        $this->addRow($backup, 'crawl-backup', FirecrawlJob::STATUS_SCRAPING, 'https://backup.example.org');
        $this->addRow($main, 'crawl-done', FirecrawlJob::STATUS_COMPLETED, 'https://done.example.org');

        $tester = new CommandTester(new JobsCommand($this->entityManager(), $this->connectionResolver()));

        self::assertSame(Command::SUCCESS, $tester->execute(['--status' => 'scraping', '--connection' => 'main']));
        $display = $tester->getDisplay();
        self::assertStringContainsString('crawl-main', $display);
        self::assertStringNotContainsString('crawl-backup', $display);
        self::assertStringNotContainsString('crawl-done', $display);
        self::assertStringContainsString('1 row(s).', $display);

        self::assertSame(Command::SUCCESS, $tester->execute(['--type' => 'scrape']));
        self::assertStringContainsString('No Firecrawl job references found.', $tester->getDisplay());

        self::assertSame(Command::SUCCESS, $tester->execute(['--limit' => '2']));
        self::assertStringContainsString('2 row(s).', $tester->getDisplay());
    }

    public function testListRejectsAnUnknownConnectionAndABadLimit(): void
    {
        $tester = new CommandTester(new JobsCommand($this->entityManager(), $this->connectionResolver()));

        self::assertSame(Command::FAILURE, $tester->execute(['--connection' => 'missing']));
        self::assertStringContainsString('Unknown Firecrawl connection "missing"', $tester->getDisplay());
        self::assertSame(Command::INVALID, $tester->execute(['--limit' => '0']));
    }

    public function testStartPrintsTheRowAsJson(): void
    {
        $main = $this->addConnection('main', self::MAIN);
        $this->fake->queue('POST', '/v2/crawl', ['success' => true, 'id' => 'crawl-1']);

        $tester = $this->startTester();

        self::assertSame(Command::SUCCESS, $tester->execute(['type' => 'crawl', 'url' => 'https://example.org', '--options' => '{"limit": 3}']));
        $row = $this->rowFromDisplay($tester->getDisplay());
        self::assertSame('scraping', $row['status']);
        self::assertSame('crawl-1', $row['firecrawlId']);
        self::assertSame(['limit' => 3], $row['options']);
        self::assertSame(['id' => (string) $main->getId(), 'name' => 'main'], $row['apiConfiguration']);
        self::assertSame(['url' => 'https://example.org', 'limit' => 3], $this->fake->getRequests(null, 'POST', '/v2/crawl')[0]['body']);
    }

    public function testStartExitsNonZeroWhenTheRowFailed(): void
    {
        $this->addConnection('main', self::MAIN);
        $this->addConnection('backup', self::BACKUP);
        $this->fake->rejectCredentials(self::BACKUP);

        $tester = $this->startTester();

        self::assertSame(Command::FAILURE, $tester->execute(['type' => 'scrape', 'url' => 'https://example.org', '--connection' => 'backup']));
        self::assertSame('failed', $this->rowFromDisplay($tester->getDisplay())['status']);
        self::assertCount(1, $this->rows);
    }

    public function testStartRejectsInvalidArguments(): void
    {
        $this->addConnection('main', self::MAIN);
        $tester = $this->startTester();

        self::assertSame(Command::INVALID, $tester->execute(['type' => 'map', 'url' => 'https://example.org']));
        self::assertSame(Command::INVALID, $tester->execute(['type' => 'crawl', 'url' => 'example.org']));
        self::assertSame(Command::INVALID, $tester->execute(['type' => 'crawl', 'url' => 'https://example.org', '--options' => '{"limit":']));
        self::assertSame(Command::INVALID, $tester->execute(['type' => 'crawl', 'url' => 'https://example.org', '--options' => '[1, 2]']));
        self::assertSame(Command::FAILURE, $tester->execute(['type' => 'crawl', 'url' => 'https://example.org', '--connection' => 'missing']));
        self::assertSame([], $this->rows);
        self::assertSame(0, $this->fake->getClientCount());
    }

    public function testStartBatchScrapeWithSeveralUrls(): void
    {
        $this->addConnection('main', self::MAIN);
        $this->fake->queue('POST', '/v2/batch/scrape', ['success' => true, 'id' => 'batch-1']);

        $tester = $this->startTester();

        self::assertSame(Command::SUCCESS, $tester->execute(['type' => 'batch_scrape', 'url' => ['https://example.org/a', 'https://example.org/b'], '--options' => '{"formats": ["markdown"]}']));
        $row = $this->rowFromDisplay($tester->getDisplay());
        self::assertSame('batch_scrape', $row['type']);
        self::assertSame('scraping', $row['status']);
        self::assertSame('batch-1', $row['firecrawlId']);
        self::assertSame('https://example.org/a', $row['url']);
        self::assertSame(['urls' => ['https://example.org/a', 'https://example.org/b'], 'formats' => ['markdown']], $row['options']);
        self::assertSame(['urls' => ['https://example.org/a', 'https://example.org/b'], 'formats' => ['markdown']], $this->fake->getRequests(null, 'POST', '/v2/batch/scrape')[0]['body']);
    }

    public function testStartScrapeAndCrawlTakeExactlyOneUrl(): void
    {
        $this->addConnection('main', self::MAIN);
        $tester = $this->startTester();

        self::assertSame(Command::INVALID, $tester->execute(['type' => 'crawl', 'url' => ['https://example.org/a', 'https://example.org/b']]));
        self::assertStringContainsString('takes exactly one URL', $tester->getDisplay());
        self::assertSame(Command::INVALID, $tester->execute(['type' => 'scrape', 'url' => ['https://example.org/a', 'https://example.org/b']]));
        self::assertSame(Command::INVALID, $tester->execute(['type' => 'batch_scrape', 'url' => ['https://example.org/a', 'example.org/b']]));
        self::assertSame([], $this->rows);
        self::assertSame(0, $this->fake->getClientCount());
    }

    public function testStartWithSeveralActiveConnectionsNeedsAChoice(): void
    {
        $this->addConnection('main', self::MAIN);
        $this->addConnection('backup', self::BACKUP);

        $tester = $this->startTester();

        self::assertSame(Command::FAILURE, $tester->execute(['type' => 'crawl', 'url' => 'https://example.org']));
        self::assertStringContainsString('Several active firecrawl connections', $tester->getDisplay());
    }

    public function testSyncByPartialUuidPrintsTheRefreshedRow(): void
    {
        $main = $this->addConnection('main', self::MAIN);
        $row = $this->addRow($main, 'crawl-1');
        $this->fake->serve('GET', '/v2/crawl/crawl-1', ['status' => 'completed', 'total' => 2, 'completed' => 2, 'creditsUsed' => 2]);
        $partial = substr((string) $row->getId(), 0, 8);

        $tester = $this->syncTester([$partial => $row]);

        self::assertSame(Command::SUCCESS, $tester->execute(['id' => $partial]));
        $printed = $this->rowFromDisplay($tester->getDisplay());
        self::assertSame((string) $row->getId(), $printed['id']);
        self::assertSame('completed', $printed['status']);
        self::assertSame(['total' => 2, 'completed' => 2, 'credits_used' => 2], $printed['resultSummary']);
    }

    public function testSyncReportsRemoteFailuresNotFoundAndAmbiguousIds(): void
    {
        $main = $this->addConnection('main', self::MAIN);
        $row = $this->addRow($main, 'crawl-1');
        $this->fake->rejectCredentials();

        $tester = $this->syncTester([(string) $row->getId() => $row]);

        self::assertSame(Command::FAILURE, $tester->execute(['id' => (string) $row->getId()]));
        self::assertSame('Firecrawl request failed with HTTP 403: Forbidden', $row->getErrorMessage());
        self::assertSame(Command::FAILURE, $tester->execute(['id' => 'deadbeef']));
        self::assertStringContainsString('Firecrawl job "deadbeef" not found.', $tester->getDisplay());
        self::assertSame(Command::FAILURE, $tester->execute(['id' => 'ambiguous']));
        self::assertStringContainsString('Ambiguous', $tester->getDisplay());
    }

    public function testSyncOfAScrapeIsANoOp(): void
    {
        $main = $this->addConnection('main', self::MAIN);
        $row = $this->addRow($main, null, FirecrawlJob::STATUS_COMPLETED);

        $tester = $this->syncTester([(string) $row->getId() => $row]);

        self::assertSame(Command::SUCCESS, $tester->execute(['id' => (string) $row->getId()]));
        self::assertStringContainsString('nothing to sync', $tester->getDisplay());
        self::assertSame(0, $this->fake->getClientCount());
    }

    public function testScanPrintsTheSummaryAndFailsOnlyWhenEveryConnectionFailed(): void
    {
        $this->addConnection('backup', self::BACKUP);
        $this->addConnection('main', self::MAIN);
        $this->fake->rejectCredentials(self::BACKUP);
        $this->fake->serve('GET', '/v2/crawl/active', ['success' => true, 'crawls' => [['id' => 'crawl-1', 'url' => 'https://docs.example.org']]], self::MAIN);

        $tester = $this->scanTester();

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        $display = $tester->getDisplay();
        self::assertStringContainsString('Created 1, unchanged 0; 1 connection(s) scanned, 0 skipped, 1 failed.', $display);
        self::assertStringContainsString('HTTP 403', $display);

        self::assertSame(Command::FAILURE, $tester->execute(['--connection' => 'backup']));
        self::assertStringContainsString('Every scanned connection failed.', $tester->getDisplay());

        self::assertSame(Command::SUCCESS, $tester->execute(['--connection' => 'main']));
        self::assertStringContainsString('Created 0, unchanged 1', $tester->getDisplay());
    }

    public function testScanWithoutConnectionsAndWithAnUnknownOne(): void
    {
        $tester = $this->scanTester();

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('No firecrawl connections found.', $tester->getDisplay());
        self::assertSame(Command::FAILURE, $tester->execute(['--connection' => 'missing']));
    }

    private function startTester(): CommandTester
    {
        return new CommandTester(new JobsStartCommand(
            new JobStarter($this->entityManager(), $this->fake, FirecrawlSecrets::create(), new NullLogger()),
            $this->connectionResolver(),
        ));
    }

    /**
     * @param array<string, FirecrawlJob> $byId ids the stubbed UuidResolver knows;
     *                                          `ambiguous` throws like UuidResolver does
     */
    private function syncTester(array $byId): CommandTester
    {
        $uuidResolver = $this->createStub(UuidResolver::class);
        $uuidResolver->method('findByPartialUuid')->willReturnCallback(static function (string $class, string $id) use ($byId): ?object {
            self::assertSame(FirecrawlJob::class, $class);
            if ('ambiguous' === $id) {
                throw new \RuntimeException('Ambiguous partial UUID "ambiguous": 2 matches.');
            }

            return $byId[$id] ?? null;
        });

        return new CommandTester(new JobsSyncCommand(new JobSyncer($this->entityManager(), $this->fake, FirecrawlSecrets::create()), $uuidResolver));
    }

    private function scanTester(): CommandTester
    {
        return new CommandTester(new JobsScanCommand(new JobScanner($this->entityManager(), $this->fake, FirecrawlSecrets::create()), $this->connectionResolver()));
    }

    /**
     * @return array<string, mixed> the JSON object at the start of the output
     */
    private function rowFromDisplay(string $display): array
    {
        $end = strpos($display, "\n}");
        self::assertNotFalse($end, 'No JSON object in the output: '.$display);

        /** @var array<string, mixed> $row */
        $row = json_decode(substr($display, 0, $end + 2), true, 512, \JSON_THROW_ON_ERROR);

        return $row;
    }
}
