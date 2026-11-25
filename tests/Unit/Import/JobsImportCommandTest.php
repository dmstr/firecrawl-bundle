<?php

// file generated with AI assistance: Claude Code - 2026-10-03 07:43:59 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\Tests\Unit\Import;

use Dmstr\ApiPlatformUtils\Service\UuidResolver;
use Dmstr\Firecrawl\Command\JobsImportCommand;
use Dmstr\Firecrawl\Entity\Refs\FirecrawlJob;
use Dmstr\Firecrawl\Import\JobImporter;
use Dmstr\Firecrawl\Import\PageOutcome;
use Dmstr\Firecrawl\Testing\FakeFirecrawlClientFactory;
use Dmstr\Firecrawl\Tests\Fixtures\FirecrawlSecrets;
use Dmstr\Firecrawl\Tests\Fixtures\InMemoryPageImporter;
use Dmstr\Firecrawl\Tests\Unit\Jobs\InMemoryJobsTrait;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Kernel-free checks of `firecrawl:jobs:import` with the in-memory page
 * importer, the fake client factory and a stubbed UuidResolver: the report
 * as JSON, the checks before the import, `--delete-vanished` and the exit
 * codes.
 */
final class JobsImportCommandTest extends TestCase
{
    use InMemoryJobsTrait;

    private const string BASE_URL = 'https://fc.example.org';
    private const string CRAWL = '/v2/crawl/job-1';

    private FakeFirecrawlClientFactory $fake;
    private InMemoryPageImporter $port;

    protected function setUp(): void
    {
        $this->fake = new FakeFirecrawlClientFactory();
        $this->port = new InMemoryPageImporter();
    }

    public function testImportPrintsTheReport(): void
    {
        $row = $this->crawl();
        $this->fake->serve('GET', self::CRAWL, self::statusPage([self::entry('a'), self::entry('b')]));
        $this->port->outcomes['https://example.org/b'] = PageOutcome::skipped('0199a5b2-0000-7000-8000-00000000000b');

        $tester = $this->tester([substr((string) $row->getId(), 0, 8) => $row]);

        self::assertSame(Command::SUCCESS, $tester->execute(['id' => substr((string) $row->getId(), 0, 8), 'collection' => 'handbook']));
        $report = self::reportFromDisplay($tester->getDisplay());
        self::assertSame(1, $report['created']);
        self::assertSame(1, $report['skipped']);
        self::assertSame(2, $report['pages']);
        self::assertSame('example.org', $report['site']);
        self::assertSame(JobImporter::DELETION_OFF, $report['deletion']);
        self::assertSame([['handbook', 'example.org']], $this->port->prepared);
    }

    public function testFailedPagesWarnButSucceed(): void
    {
        $row = $this->crawl();
        $this->fake->serve('GET', self::CRAWL, self::statusPage([self::entry('a'), self::entry('b')]));
        $this->port->outcomes['https://example.org/b'] = PageOutcome::failed('Extraction failed');

        $tester = $this->tester([(string) $row->getId() => $row]);

        self::assertSame(Command::SUCCESS, $tester->execute(['id' => (string) $row->getId(), 'collection' => 'handbook']));
        self::assertStringContainsString('1 of 2 page(s) failed.', self::flat($tester->getDisplay()));
    }

    public function testDeleteVanishedIsPassedThrough(): void
    {
        $row = $this->crawl();
        $this->fake->serve('GET', self::CRAWL, self::statusPage([self::entry('a')]));
        $this->port->deleteResult = 2;

        $tester = $this->tester([(string) $row->getId() => $row]);

        self::assertSame(Command::SUCCESS, $tester->execute(['id' => (string) $row->getId(), 'collection' => 'handbook', '--delete-vanished' => true]));
        $report = self::reportFromDisplay($tester->getDisplay());
        self::assertSame(JobImporter::DELETION_DONE, $report['deletion']);
        self::assertSame(2, $report['deleted']);
        self::assertSame([['https://example.org/a']], $this->port->deleteCalls);
    }

    public function testChecksFailBeforeAnythingIsFetched(): void
    {
        $main = $this->addConnection('main', self::BASE_URL);
        $scraping = $this->addRow($main, 'job-2', FirecrawlJob::STATUS_SCRAPING);
        $batch = $this->addRow($main, 'batch-1', FirecrawlJob::STATUS_COMPLETED)->setType(FirecrawlJob::TYPE_BATCH_SCRAPE);
        $crawl = $this->addRow($main, 'job-1', FirecrawlJob::STATUS_COMPLETED);
        $this->port->unknownCollections = ['missing'];

        $tester = $this->tester(['scraping' => $scraping, 'batch' => $batch, 'crawl' => $crawl]);

        self::assertSame(Command::FAILURE, $tester->execute(['id' => 'scraping', 'collection' => 'handbook']));
        self::assertStringContainsString('only completed jobs can be imported', self::flat($tester->getDisplay()));

        self::assertSame(Command::FAILURE, $tester->execute(['id' => 'batch', 'collection' => 'handbook', '--delete-vanished' => true]));
        self::assertStringContainsString('deleteVanished is only supported for crawls', self::flat($tester->getDisplay()));

        self::assertSame(Command::FAILURE, $tester->execute(['id' => 'crawl', 'collection' => 'missing']));
        self::assertStringContainsString('Unknown collection "missing"', self::flat($tester->getDisplay()));

        self::assertSame(0, $this->fake->getClientCount());
        self::assertSame([], $this->port->imported);
    }

    public function testUnknownAndAmbiguousIds(): void
    {
        $tester = $this->tester([]);

        self::assertSame(Command::FAILURE, $tester->execute(['id' => 'deadbeef', 'collection' => 'handbook']));
        self::assertStringContainsString('Firecrawl job "deadbeef" not found.', self::flat($tester->getDisplay()));
        self::assertSame(Command::FAILURE, $tester->execute(['id' => 'ambiguous', 'collection' => 'handbook']));
        self::assertStringContainsString('Ambiguous', self::flat($tester->getDisplay()));
    }

    public function testFailedImportPrintsThePartialReportAndFails(): void
    {
        $row = $this->crawl();
        $this->fake
            ->serve('GET', self::CRAWL, self::statusPage([self::entry('a')], self::BASE_URL.self::CRAWL.'?skip=1'))
            ->serve('GET', self::CRAWL.'?skip=1', ['success' => false, 'error' => 'Job expired']);

        $tester = $this->tester([(string) $row->getId() => $row]);

        self::assertSame(Command::FAILURE, $tester->execute(['id' => (string) $row->getId(), 'collection' => 'handbook', '--delete-vanished' => true]));
        $report = self::reportFromDisplay($tester->getDisplay());
        self::assertSame(1, $report['created']);
        self::assertSame(JobImporter::DELETION_SKIPPED, $report['deletion']);
        self::assertStringContainsString('expired or is unknown', self::flat($tester->getDisplay()));
        self::assertSame([], $this->port->deleteCalls);
    }

    private function crawl(): FirecrawlJob
    {
        return $this->addRow($this->addConnection('main', self::BASE_URL), 'job-1', FirecrawlJob::STATUS_COMPLETED, 'https://example.org/');
    }

    /**
     * @param array<string, FirecrawlJob> $byId ids the stubbed UuidResolver knows; `ambiguous` throws like UuidResolver does
     */
    private function tester(array $byId): CommandTester
    {
        $uuidResolver = $this->createStub(UuidResolver::class);
        $uuidResolver->method('findByPartialUuid')->willReturnCallback(static function (string $class, string $id) use ($byId): ?object {
            self::assertSame(FirecrawlJob::class, $class);
            if ('ambiguous' === $id) {
                throw new \RuntimeException('Ambiguous partial UUID "ambiguous": 2 matches.');
            }

            return $byId[$id] ?? null;
        });

        return new CommandTester(new JobsImportCommand(new JobImporter($this->port, $this->fake, FirecrawlSecrets::create(), new NullLogger()), $uuidResolver));
    }

    /** The output with line breaks and padding of SymfonyStyle blocks collapsed. */
    private static function flat(string $display): string
    {
        return (string) preg_replace('/\s+/', ' ', $display);
    }

    /**
     * @return array<string, mixed> the JSON report at the start of the output
     */
    private static function reportFromDisplay(string $display): array
    {
        $end = strpos($display, "\n}");
        self::assertNotFalse($end, 'No JSON object in the output: '.$display);

        /** @var array<string, mixed> $report */
        $report = json_decode(substr($display, 0, $end + 2), true, 512, \JSON_THROW_ON_ERROR);

        return $report;
    }

    /**
     * @return array<string, mixed>
     */
    private static function entry(string $slug): array
    {
        return ['markdown' => '# Page '.$slug, 'metadata' => ['sourceURL' => 'https://example.org/'.$slug]];
    }

    /**
     * @param list<mixed> $data
     *
     * @return array<string, mixed>
     */
    private static function statusPage(array $data, ?string $next = null): array
    {
        $response = ['success' => true, 'status' => 'completed', 'total' => \count($data), 'completed' => \count($data), 'data' => $data];
        if (null !== $next) {
            $response['next'] = $next;
        }

        return $response;
    }
}
