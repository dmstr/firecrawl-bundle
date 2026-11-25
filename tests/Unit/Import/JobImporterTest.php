<?php

// file generated with AI assistance: Claude Code - 2026-10-03 07:43:59 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\Tests\Unit\Import;

use Dmstr\ApiConfiguration\Entity\ApiConfiguration;
use Dmstr\Firecrawl\Client\FirecrawlApiException;
use Dmstr\Firecrawl\Entity\Refs\FirecrawlJob;
use Dmstr\Firecrawl\Import\ImportFailedException;
use Dmstr\Firecrawl\Import\ImportTarget;
use Dmstr\Firecrawl\Import\JobImporter;
use Dmstr\Firecrawl\Import\PageOutcome;
use Dmstr\Firecrawl\Import\ResultReaderException;
use Dmstr\Firecrawl\Testing\FakeFirecrawlClientFactory;
use Dmstr\Firecrawl\Tests\Fixtures\FirecrawlSecrets;
use Dmstr\Firecrawl\Tests\Fixtures\InMemoryPageImporter;
use Dmstr\Firecrawl\Tests\Unit\Jobs\InMemoryJobsTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Kernel-free checks of the import orchestration with an in-memory page
 * importer and the fake client factory: the checks before a job exists,
 * the site, counters and failures of the report, the progress sequence,
 * the deletion gate, the failure policy (recoverable or not) and the log
 * lines.
 */
final class JobImporterTest extends TestCase
{
    use InMemoryJobsTrait;

    private const string BASE_URL = 'https://fc.example.org';
    private const string CRAWL = '/v2/crawl/job-1';
    private const string BATCH = '/v2/batch/scrape/batch-1';

    private FakeFirecrawlClientFactory $fake;
    private InMemoryPageImporter $port;
    private ApiConfiguration $connection;

    /** @var \ArrayObject<int, array{level: mixed, message: string, context: array<string, mixed>}> */
    private \ArrayObject $logs;

    protected function setUp(): void
    {
        $this->fake = new FakeFirecrawlClientFactory();
        $this->port = new InMemoryPageImporter();
        $this->connection = $this->addConnection('main', self::BASE_URL);
        $this->logs = new \ArrayObject();
    }

    // --- checks before a job exists ---------------------------------------------------------------------------

    public function testCompletedCrawlAndBatchScrapeAreImportable(): void
    {
        $this->importer()->assertImportable($this->crawl(), false);
        $this->importer()->assertImportable($this->crawl(), true);
        $this->importer()->assertImportable($this->batch(), false);

        self::assertSame(0, $this->fake->getClientCount());
    }

    /**
     * @return iterable<string, array{string, string, string|null, bool, string}>
     */
    public static function notImportable(): iterable
    {
        yield 'scrape' => [FirecrawlJob::TYPE_SCRAPE, FirecrawlJob::STATUS_COMPLETED, null, false, 'type "scrape" cannot be imported'];
        yield 'crawl still scraping' => [FirecrawlJob::TYPE_CRAWL, FirecrawlJob::STATUS_SCRAPING, 'job-1', false, 'status "scraping"'];
        yield 'crawl failed' => [FirecrawlJob::TYPE_CRAWL, FirecrawlJob::STATUS_FAILED, 'job-1', false, 'status "failed"'];
        yield 'crawl without remote id' => [FirecrawlJob::TYPE_CRAWL, FirecrawlJob::STATUS_COMPLETED, null, false, 'no remote id'];
        yield 'batch scrape still scraping' => [FirecrawlJob::TYPE_BATCH_SCRAPE, FirecrawlJob::STATUS_SCRAPING, 'batch-1', false, 'status "scraping"'];
        yield 'deleteVanished on a batch scrape' => [FirecrawlJob::TYPE_BATCH_SCRAPE, FirecrawlJob::STATUS_COMPLETED, 'batch-1', true, 'deleteVanished is only supported for crawls'];
    }

    #[DataProvider('notImportable')]
    public function testNotImportableIsUnprocessable(string $type, string $status, ?string $remoteId, bool $deleteVanished, string $message): void
    {
        $row = $this->addRow($this->connection, $remoteId, $status)->setType($type);

        try {
            $this->importer()->assertImportable($row, $deleteVanished);
            self::fail('Expected an UnprocessableEntityHttpException.');
        } catch (UnprocessableEntityHttpException $e) {
            self::assertSame(422, $e->getStatusCode());
            self::assertStringContainsString($message, $e->getMessage());
        }

        self::assertSame(0, $this->fake->getClientCount());
    }

    public function testSiteIsTheLowerCasedHostOfACrawlAndNullForABatchScrape(): void
    {
        self::assertSame('docs.example.org', JobImporter::site($this->crawl('https://Docs.Example.ORG/start/')));
        self::assertSame('www.example.org', JobImporter::site($this->crawl('https://www.example.org')));
        self::assertNull(JobImporter::site($this->batch()));
        self::assertNull(JobImporter::site($this->crawl('not a url')));
    }

    public function testPrepareHandsTheSiteToThePort(): void
    {
        $target = $this->importer()->prepare($this->crawl('https://Docs.Example.org/start'), 'handbook');
        self::assertSame(InMemoryPageImporter::COLLECTION_ID, $target->collectionId);
        self::assertSame('docs.example.org', $target->site);

        $this->importer()->prepare($this->batch(), 'handbook');

        self::assertSame([['handbook', 'docs.example.org'], ['handbook', null]], $this->port->prepared);
    }

    public function testPrepareSurfacesTheUnprocessableCollection(): void
    {
        $this->port->unknownCollections = ['missing'];

        $this->expectException(UnprocessableEntityHttpException::class);

        $this->importer()->prepare($this->crawl(), 'missing');
    }

    // --- report -----------------------------------------------------------------------------------------------

    public function testCountersAndFailuresPerOutcome(): void
    {
        $this->fake->serve('GET', self::CRAWL, self::statusPage([
            self::entry('created'),
            self::entry('replaced'),
            self::entry('skipped'),
            self::entry('conflict'),
            ['metadata' => ['sourceURL' => 'https://example.org/empty']],
        ]));
        $this->port->outcomes = [
            'https://example.org/replaced' => PageOutcome::replaced('0199a5b2-0000-7000-8000-00000000000a'),
            'https://example.org/skipped' => PageOutcome::skipped('0199a5b2-0000-7000-8000-00000000000b'),
            'https://example.org/conflict' => PageOutcome::failed('content already stored as /api/admin/documents/0199a5b2-0000-7000-8000-00000000000c', '0199a5b2-0000-7000-8000-00000000000c'),
        ];
        $job = $this->crawl();
        $target = $this->importer()->prepare($job, 'handbook');

        $report = $this->importer()->import($job, $target, false);

        self::assertSame([
            'created' => 1,
            'replaced' => 1,
            'skipped' => 1,
            'deleted' => 0,
            'failed' => 2,
            'pages' => 5,
            'site' => 'example.org',
            'failures' => [
                ['url' => 'https://example.org/conflict', 'reason' => 'content already stored as /api/admin/documents/0199a5b2-0000-7000-8000-00000000000c', 'document' => '/api/admin/documents/0199a5b2-0000-7000-8000-00000000000c'],
                ['url' => 'https://example.org/empty', 'reason' => 'The result entry has no Markdown content (request the "markdown" format).'],
            ],
            'deletion' => JobImporter::DELETION_OFF,
        ], $report);

        // Rejected entries never reach the port; every other page does, with the same target.
        self::assertSame(['https://example.org/created', 'https://example.org/replaced', 'https://example.org/skipped', 'https://example.org/conflict'], $this->port->importedUrls());
        foreach ($this->port->targets as $handed) {
            self::assertSame($target, $handed);
        }
        self::assertSame([], $this->port->deleteCalls);
    }

    public function testFailedOutcomeWithoutDocumentHasNoDocumentKey(): void
    {
        $this->fake->serve('GET', self::CRAWL, self::statusPage([self::entry('a'), self::entry('big')]));
        $this->port->outcomes['https://example.org/big'] = PageOutcome::failed('The file (30 bytes) exceeds the upload limit of 1 MB (setting documents.max_upload_size_mb).');

        $report = $this->import($this->crawl());

        self::assertSame([['url' => 'https://example.org/big', 'reason' => 'The file (30 bytes) exceeds the upload limit of 1 MB (setting documents.max_upload_size_mb).']], $report['failures']);
    }

    public function testPagesAreHandedOverNormalised(): void
    {
        $this->fake->serve('GET', self::CRAWL, self::statusPage([
            ['markdown' => "Title\n=====\n\nBody", 'metadata' => ['sourceURL' => 'HTTPS://Example.org/Docs/#intro', 'title' => 'Docs', 'statusCode' => 200]],
        ]));

        $this->import($this->crawl());

        self::assertCount(1, $this->port->imported);
        $page = $this->port->imported[0];
        self::assertSame('https://example.org/Docs', $page->url);
        self::assertSame("# Title\n\nBody", $page->markdown);
        self::assertSame('Docs', $page->title);
        self::assertSame(200, $page->statusCode);
    }

    public function testTheRowsOwnConnectionIsUsed(): void
    {
        $backup = $this->addConnection('backup', 'https://fc-backup.example.org');
        $this->fake->serve('GET', self::CRAWL, self::statusPage([self::entry('a')]), 'https://fc-backup.example.org');
        $job = $this->addRow($backup, 'job-1', FirecrawlJob::STATUS_COMPLETED, 'https://example.org');

        $this->import($job);

        self::assertSame([$backup->getConfigJson()], $this->fake->getCreatedConfigs());
        self::assertSame(1, $this->fake->getRequestCount('https://fc-backup.example.org', 'GET', self::CRAWL));
        self::assertSame(0, $this->fake->getRequestCount(self::BASE_URL));
    }

    public function testBatchScrapeIsImportedThroughItsEndpointWithoutSite(): void
    {
        $this->fake
            ->serve('GET', self::BATCH, self::statusPage([self::entry('a')], self::BASE_URL.self::BATCH.'?skip=1'))
            ->serve('GET', self::BATCH.'?skip=1', self::statusPage([self::entry('b')]));

        $report = $this->import($this->batch());

        self::assertSame(2, $report['created']);
        self::assertNull($report['site']);
        self::assertSame(1, $this->fake->getRequestCount(null, 'GET', self::BATCH));
        self::assertSame(0, $this->fake->getRequestCount(null, 'GET', '/v2/crawl/batch-1'));
    }

    // --- progress ---------------------------------------------------------------------------------------------

    public function testProgressIsReportedPerPageAndWithinAPageOnlyOnChange(): void
    {
        $this->fake
            ->serve('GET', self::CRAWL, self::statusPage([self::entry('a'), self::entry('b')], self::BASE_URL.self::CRAWL.'?skip=2', total: 4))
            ->serve('GET', self::CRAWL.'?skip=2', self::statusPage([['metadata' => ['sourceURL' => 'https://example.org/c']], self::entry('d')], total: 4));
        $this->port->progressSteps = 2;
        $progress = [];

        $this->import($this->crawl(), false, static function (int $percent) use (&$progress): void {
            $progress[] = $percent;
        });

        // Two embedding steps per imported page; the rejected page c jumps straight to 75.
        self::assertSame([12, 25, 37, 50, 75, 87, 100], $progress);
    }

    public function testProgressNeverExceedsOneHundredWhenTheTotalIsTooSmall(): void
    {
        $this->fake->serve('GET', self::CRAWL, self::statusPage([self::entry('a'), self::entry('b'), self::entry('c')], total: 1));
        $progress = [];

        $this->import($this->crawl(), false, static function (int $percent) use (&$progress): void {
            $progress[] = $percent;
        });

        self::assertNotSame([], $progress);
        self::assertLessThanOrEqual(100, max($progress));
        self::assertSame(100, end($progress));
    }

    // --- deletion gate ----------------------------------------------------------------------------------------

    public function testDeletionOff(): void
    {
        $this->fake->serve('GET', self::CRAWL, self::statusPage([self::entry('a')]));

        $report = $this->import($this->crawl(), false);

        self::assertSame(JobImporter::DELETION_OFF, $report['deletion']);
        self::assertArrayNotHasKey('deletionSkippedReason', $report);
        self::assertSame(0, $report['deleted']);
        self::assertSame([], $this->port->deleteCalls);
    }

    public function testDeletionOnAfterACleanImportRemovesVanishedPages(): void
    {
        $this->fake
            ->serve('GET', self::CRAWL, self::statusPage([self::entry('a'), self::entry('b')], self::BASE_URL.self::CRAWL.'?skip=2'))
            ->serve('GET', self::CRAWL.'?skip=2', self::statusPage([['markdown' => 'Same page', 'metadata' => ['sourceURL' => 'https://example.org/a/#again']], self::entry('c')]));
        $this->port->outcomes['https://example.org/b'] = PageOutcome::skipped('0199a5b2-0000-7000-8000-00000000000b');
        $this->port->deleteResult = 3;

        $report = $this->import($this->crawl('https://example.org'), true);

        self::assertSame(JobImporter::DELETION_DONE, $report['deletion']);
        self::assertArrayNotHasKey('deletionSkippedReason', $report);
        self::assertSame(3, $report['deleted']);
        // The URLs seen, normalised and without duplicates, go to the port once.
        self::assertSame([['https://example.org/a', 'https://example.org/b', 'https://example.org/c']], $this->port->deleteCalls);
        self::assertSame('example.org', end($this->port->targets)->site);
    }

    public function testDeletionOnIsSkippedAfterOneFailedPage(): void
    {
        $this->fake->serve('GET', self::CRAWL, self::statusPage([self::entry('a'), self::entry('b')]));
        $this->port->outcomes['https://example.org/b'] = PageOutcome::failed('Embedding failed: connection refused', '0199a5b2-0000-7000-8000-00000000000b');

        $report = $this->import($this->crawl(), true);

        self::assertSame(JobImporter::DELETION_SKIPPED, $report['deletion']);
        self::assertSame('1 page(s) failed; nothing is removed after an incomplete import.', $report['deletionSkippedReason']);
        self::assertSame(0, $report['deleted']);
        self::assertSame([], $this->port->deleteCalls);
    }

    public function testDeletionOnIsSkippedAfterARejectedEntry(): void
    {
        $this->fake->serve('GET', self::CRAWL, self::statusPage([self::entry('a'), ['markdown' => 'No URL']]));

        $report = $this->import($this->crawl(), true);

        self::assertSame(JobImporter::DELETION_SKIPPED, $report['deletion']);
        self::assertStringContainsString('1 page(s) failed', $report['deletionSkippedReason']);
        self::assertSame([], $this->port->deleteCalls);
    }

    public function testDeletionOnWithExpiryMidWayFailsWithAPartialReport(): void
    {
        $this->fake
            ->serve('GET', self::CRAWL, self::statusPage([self::entry('a'), self::entry('b')], self::BASE_URL.self::CRAWL.'?skip=2'))
            ->queueException('GET', self::CRAWL.'?skip=2', new FirecrawlApiException('Firecrawl request failed with HTTP 404: Job not found', 404));

        try {
            $this->import($this->crawl(), true);
            self::fail('Expected an ImportFailedException.');
        } catch (ImportFailedException $e) {
            self::assertTrue($e->isUnrecoverable());
            self::assertInstanceOf(ResultReaderException::class, $e->getPrevious());
            self::assertStringContainsString('result page 2', $e->getMessage());
            $report = $e->getReport();
            self::assertSame(2, $report['created']);
            self::assertSame(2, $report['pages']);
            self::assertSame(JobImporter::DELETION_SKIPPED, $report['deletion']);
            self::assertSame('The result was not read completely.', $report['deletionSkippedReason']);
        }

        self::assertSame([], $this->port->deleteCalls);
        self::assertSame(['https://example.org/a', 'https://example.org/b'], $this->port->importedUrls());
    }

    public function testDeletionOnABatchScrapeIsSkippedEvenWithoutTheCheck(): void
    {
        $this->fake->serve('GET', self::BATCH, self::statusPage([self::entry('a')]));

        // assertImportable() refuses this with 422 (see notImportable); import() itself never deletes for a batch scrape.
        $report = $this->import($this->batch(), true);

        self::assertSame(JobImporter::DELETION_SKIPPED, $report['deletion']);
        self::assertSame('Removal of vanished pages applies to crawls only.', $report['deletionSkippedReason']);
        self::assertSame([], $this->port->deleteCalls);
    }

    public function testDeletionOnACrawlWithoutSiteIsSkipped(): void
    {
        $this->fake->serve('GET', self::CRAWL, self::statusPage([self::entry('a')]));

        $report = $this->import($this->crawl('not a url'), true);

        self::assertSame(JobImporter::DELETION_SKIPPED, $report['deletion']);
        self::assertStringContainsString('has no site', $report['deletionSkippedReason']);
        self::assertSame([], $this->port->deleteCalls);
    }

    // --- failure policy ---------------------------------------------------------------------------------------

    public function testEveryPageFailedIsARecoverableFailure(): void
    {
        $this->fake->serve('GET', self::CRAWL, self::statusPage([self::entry('a'), self::entry('b')]));
        $this->port->outcomes = [
            'https://example.org/a' => PageOutcome::failed('Embedding failed: connection refused', '0199a5b2-0000-7000-8000-00000000000a'),
            'https://example.org/b' => PageOutcome::failed('Embedding failed: connection refused', '0199a5b2-0000-7000-8000-00000000000b'),
        ];

        try {
            $this->import($this->crawl(), true);
            self::fail('Expected an ImportFailedException.');
        } catch (ImportFailedException $e) {
            self::assertFalse($e->isUnrecoverable());
            self::assertStringContainsString('Every page of Firecrawl job', $e->getMessage());
            self::assertStringContainsString('Embedding failed: connection refused', $e->getMessage());
            $report = $e->getReport();
            self::assertSame(2, $report['failed']);
            self::assertSame(2, $report['pages']);
            self::assertCount(2, $report['failures']);
            self::assertSame(JobImporter::DELETION_SKIPPED, $report['deletion']);
        }

        self::assertSame([], $this->port->deleteCalls);
    }

    public function testZeroPagesIsAnUnrecoverableFailure(): void
    {
        $this->fake->serve('GET', self::CRAWL, self::statusPage([]));

        try {
            $this->import($this->crawl());
            self::fail('Expected an ImportFailedException.');
        } catch (ImportFailedException $e) {
            self::assertTrue($e->isUnrecoverable());
            self::assertStringContainsString('holds no pages', $e->getMessage());
            self::assertSame(0, $e->getReport()['pages']);
        }
    }

    public function testRemoteStatusOtherThanCompletedIsUnrecoverable(): void
    {
        $this->fake->serve('GET', self::CRAWL, ['success' => true, 'status' => 'scraping', 'data' => []]);

        try {
            $this->import($this->crawl());
            self::fail('Expected an ImportFailedException.');
        } catch (ImportFailedException $e) {
            self::assertTrue($e->isUnrecoverable());
            self::assertStringContainsString('is not completed', $e->getMessage());
        }

        self::assertSame([], $this->port->imported);
    }

    public function testPastExpiryIsUnrecoverableWithoutImportingAnything(): void
    {
        $this->fake->serve('GET', self::CRAWL, self::statusPage([self::entry('a')], expiresAt: '2026-10-03T08:00:00Z'));

        try {
            $this->importer()->import($this->crawl(), $this->target(), false, null, new \DateTimeImmutable('2026-10-03T09:00:00Z'));
            self::fail('Expected an ImportFailedException.');
        } catch (ImportFailedException $e) {
            self::assertTrue($e->isUnrecoverable());
            self::assertStringContainsString('expired at', $e->getMessage());
        }

        self::assertSame([], $this->port->imported);
        self::assertSame(1, $this->fake->getRequestCount());
    }

    public function testForeignContinuationLinkIsUnrecoverable(): void
    {
        $this->fake->serve('GET', self::CRAWL, self::statusPage([self::entry('a')], self::BASE_URL.'/v2/crawl/other-job?skip=1'));

        try {
            $this->import($this->crawl(), true);
            self::fail('Expected an ImportFailedException.');
        } catch (ImportFailedException $e) {
            self::assertTrue($e->isUnrecoverable());
            self::assertStringContainsString('does not belong to this job', $e->getMessage());
            self::assertSame(1, $e->getReport()['created']);
            self::assertSame(JobImporter::DELETION_SKIPPED, $e->getReport()['deletion']);
        }

        self::assertSame([], $this->port->deleteCalls);
    }

    public function testTransportFailureIsRecoverableWithThePartialReport(): void
    {
        $error = new FirecrawlApiException('Firecrawl request failed with HTTP 502: Bad Gateway', 502);
        $this->fake
            ->serve('GET', self::CRAWL, self::statusPage([self::entry('a')], self::BASE_URL.self::CRAWL.'?skip=1'))
            ->queueException('GET', self::CRAWL.'?skip=1', $error);

        try {
            $this->import($this->crawl());
            self::fail('Expected an ImportFailedException.');
        } catch (ImportFailedException $e) {
            self::assertFalse($e->isUnrecoverable());
            self::assertSame($error, $e->getPrevious());
            self::assertStringContainsString('aborted after 1 page(s)', $e->getMessage());
            self::assertSame(1, $e->getReport()['created']);
        }
    }

    public function testPortExceptionIsRecoverable(): void
    {
        $error = new \RuntimeException('Import of https://example.org/b stopped: the entity manager is closed.');
        $this->fake->serve('GET', self::CRAWL, self::statusPage([self::entry('a'), self::entry('b'), self::entry('c')]));
        $this->port->outcomes['https://example.org/b'] = $error;

        try {
            $this->import($this->crawl());
            self::fail('Expected an ImportFailedException.');
        } catch (ImportFailedException $e) {
            self::assertFalse($e->isUnrecoverable());
            self::assertSame($error, $e->getPrevious());
            self::assertSame(1, $e->getReport()['created']);
            self::assertSame(2, $e->getReport()['pages']);
        }

        // Page c is never reached.
        self::assertSame(['https://example.org/a', 'https://example.org/b'], $this->port->importedUrls());
    }

    // --- log --------------------------------------------------------------------------------------------------

    public function testOneInfoLinePerImportAndOneWarningPerFailedPage(): void
    {
        $this->fake->serve('GET', self::CRAWL, self::statusPage([self::entry('a'), self::entry('b'), ['markdown' => 'No URL']]));
        $this->port->outcomes['https://example.org/b'] = PageOutcome::failed('Extraction failed', '0199a5b2-0000-7000-8000-00000000000b');
        $job = $this->crawl();

        $this->import($job);

        $levels = array_column($this->logs->getArrayCopy(), 'level');
        self::assertSame(['warning', 'warning', 'info'], $levels);
        $info = $this->logs[2]['context'];
        self::assertSame((string) $job->getId(), $info['job_id']);
        self::assertSame('job-1', $info['firecrawl_id']);
        self::assertSame('handbook', $info['collection']);
        self::assertSame(1, $info['created']);
        self::assertSame(2, $info['failed']);
        self::assertSame('/api/admin/documents/0199a5b2-0000-7000-8000-00000000000b', $this->logs[0]['context']['document']);
        self::assertSame('(none)', $this->logs[1]['context']['url']);
    }

    public function testFailedImportLogsAnError(): void
    {
        $this->fake->serve('GET', self::CRAWL, self::statusPage([]));

        try {
            $this->import($this->crawl());
        } catch (ImportFailedException) {
        }

        self::assertSame(['error'], array_column($this->logs->getArrayCopy(), 'level'));
    }

    // --- helpers ----------------------------------------------------------------------------------------------

    private function importer(): JobImporter
    {
        $logger = new class($this->logs) extends AbstractLogger {
            /** @param \ArrayObject<int, array<string, mixed>> $records */
            public function __construct(private readonly \ArrayObject $records)
            {
            }

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records->append(['level' => $level, 'message' => (string) $message, 'context' => $context]);
            }
        };

        return new JobImporter($this->port, $this->fake, FirecrawlSecrets::create(), $logger);
    }

    /**
     * @return array<string, mixed> the report
     */
    private function import(FirecrawlJob $job, bool $deleteVanished = false, ?callable $progress = null): array
    {
        $importer = $this->importer();

        return $importer->import($job, $importer->prepare($job, 'handbook'), $deleteVanished, $progress);
    }

    private function target(): ImportTarget
    {
        return new ImportTarget(InMemoryPageImporter::COLLECTION_ID, 'handbook', 'example.org');
    }

    private function crawl(string $url = 'https://example.org/'): FirecrawlJob
    {
        return $this->addRow($this->connection, 'job-1', FirecrawlJob::STATUS_COMPLETED, $url)->setType(FirecrawlJob::TYPE_CRAWL);
    }

    private function batch(): FirecrawlJob
    {
        return $this->addRow($this->connection, 'batch-1', FirecrawlJob::STATUS_COMPLETED, 'https://example.org/a')
            ->setType(FirecrawlJob::TYPE_BATCH_SCRAPE)
            ->setOptions(['urls' => ['https://example.org/a', 'https://example.org/b']]);
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
     * @return array<string, mixed> a completed result page
     */
    private static function statusPage(array $data, ?string $next = null, ?int $total = null, string $expiresAt = '2099-01-01T00:00:00Z'): array
    {
        $response = ['success' => true, 'status' => 'completed', 'total' => $total ?? \count($data), 'completed' => $total ?? \count($data), 'expiresAt' => $expiresAt, 'data' => $data];
        if (null !== $next) {
            $response['next'] = $next;
        }

        return $response;
    }
}
