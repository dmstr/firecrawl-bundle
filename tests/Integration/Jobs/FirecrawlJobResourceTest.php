<?php

// file generated with AI assistance: Claude Code - 2026-10-03 00:10:23 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\Tests\Integration\Jobs;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use Dmstr\Firecrawl\Client\FirecrawlApiException;
use Dmstr\Firecrawl\Entity\Refs\FirecrawlJob;
use Dmstr\Firecrawl\Tests\Kernel\TestHeaderAuthenticator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Functional test of the job reference table under /api/refs/firecrawl_jobs
 * against the fake client factory: start (scrape, crawl, batch scrape,
 * remote failure, validation and connection errors), sync (crawl and batch
 * scrape), scan (job, idempotence, partial
 * and total failure), the connection filter and read access for ROLE_USER.
 * Needs the PostgreSQL database from `DATABASE_URL`; the test firewall
 * authenticates as admin. Each test starts from empty tables
 * ({@see JobsDatabaseTrait::cleanUp()}), so only the connections created
 * here exist.
 * Role checks (401/403) are {@see \Dmstr\Firecrawl\Tests\Integration\Access\FirecrawlAccessTest};
 * the import into documents belongs to the host application's port.
 */
#[Group('database')]
final class FirecrawlJobResourceTest extends ApiTestCase
{
    use JobsDatabaseTrait;

    protected static ?bool $alwaysBootKernel = true;

    private const string MAIN = 'https://main.jobs.example.org';
    private const string BACKUP = 'https://backup.jobs.example.org';
    private const string ACTIVE = '/v2/crawl/active';

    public function testStartScrapeWithTheImplicitConnection(): void
    {
        $client = $this->client();
        $main = $this->connection('main', self::MAIN);
        $fake = $this->fake();
        $fake->queue('POST', '/v2/scrape', ['success' => true, 'data' => ['markdown' => '# Hello', 'metadata' => ['title' => 'Hello']]]);

        $this->post($client, self::COLLECTION, '{"type": "scrape", "url": "https://example.org", "options": {"formats": ["markdown"]}}');

        self::assertResponseStatusCodeSame(201);
        $data = $client->getResponse()->toArray();
        self::assertSame('completed', $data['status']);
        self::assertSame('scrape', $data['type']);
        self::assertSame(['metadata' => ['title' => 'Hello'], 'markdown_length' => 7], $data['resultSummary']);
        self::assertSame(self::CONNECTIONS.'/'.$main->getId(), $data['apiConfiguration']);
        self::assertSame(1, $fake->getRequestCount(self::MAIN, 'POST', '/v2/scrape'));
        self::assertSame(['url' => 'https://example.org', 'formats' => ['markdown']], $fake->getRequests(self::MAIN, 'POST', '/v2/scrape')[0]['body']);
        self::assertCount(1, self::rows($main));
    }

    public function testStartCrawlStoresTheRemoteId(): void
    {
        $client = $this->client();
        $this->connection('main', self::MAIN);
        $backup = $this->connection('backup', self::BACKUP);
        $this->fake()->queue('POST', '/v2/crawl', ['success' => true, 'id' => 'crawl-1', 'url' => self::BACKUP.'/v2/crawl/crawl-1'], self::BACKUP);

        $this->post($client, self::COLLECTION, json_encode(['url' => 'https://example.org', 'apiConfiguration' => self::PREFIX.'backup'], \JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(201);
        $data = $client->getResponse()->toArray();
        self::assertSame('crawl', $data['type']);
        self::assertSame('scraping', $data['status']);
        self::assertSame('crawl-1', $data['firecrawlId']);
        self::assertSame(['crawl-1'], array_keys(self::rows($backup)));
    }

    public function testStartBatchScrapeStoresTheUrlList(): void
    {
        $client = $this->client();
        $main = $this->connection('main', self::MAIN);
        $fake = $this->fake();
        $fake->queue('POST', '/v2/batch/scrape', ['success' => true, 'id' => 'batch-1', 'url' => self::MAIN.'/v2/batch/scrape/batch-1']);
        $urls = ['https://example.org/a', 'https://example.org/b'];

        $this->post($client, self::COLLECTION, json_encode(['type' => 'batch_scrape', 'urls' => $urls, 'options' => ['formats' => ['markdown']]], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES));

        self::assertResponseStatusCodeSame(201);
        $data = $client->getResponse()->toArray();
        self::assertSame('batch_scrape', $data['type']);
        self::assertSame('scraping', $data['status']);
        self::assertSame('batch-1', $data['firecrawlId']);
        self::assertSame('https://example.org/a', $data['url']);
        self::assertSame(['urls' => $urls, 'formats' => ['markdown']], $data['options']);
        self::assertSame(['urls' => $urls, 'formats' => ['markdown']], $fake->getRequests(self::MAIN, 'POST', '/v2/batch/scrape')[0]['body']);
        self::assertSame(0, $fake->getRequestCount(null, 'POST', '/v2/crawl'));

        $rows = self::rows($main);
        self::assertSame(['batch-1'], array_keys($rows));
        self::assertSame('https://example.org/a', $rows['batch-1']['url']);
        self::assertSame(['urls' => $urls, 'formats' => ['markdown']], json_decode((string) $rows['batch-1']['options'], true));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidBatchBodies(): iterable
    {
        yield 'batch scrape without urls' => ['{"type": "batch_scrape", "url": "https://example.org"}'];
        yield 'batch scrape with an empty list' => ['{"type": "batch_scrape", "urls": []}'];
        yield 'batch scrape with a relative URL' => ['{"type": "batch_scrape", "urls": ["https://example.org/a", "/b"]}'];
        yield 'crawl without url' => ['{"type": "crawl", "urls": ["https://example.org/a"]}'];
        yield 'scrape without url' => ['{"type": "scrape"}'];
    }

    #[DataProvider('invalidBatchBodies')]
    public function testStartBodyWithoutTheRequiredUrlsIsUnprocessable(string $body): void
    {
        $client = $this->client();
        $main = $this->connection('main', self::MAIN);

        $this->post($client, self::COLLECTION, $body);

        self::assertResponseStatusCodeSame(422);
        self::assertSame([], self::rows($main));
        self::assertSame(0, $this->fake()->getClientCount());
    }

    public function testRemoteFailureIsStoredAsAFailedRow(): void
    {
        $client = $this->client();
        $main = $this->connection('main', self::MAIN);
        $this->fake()->queueException('POST', '/v2/crawl', new FirecrawlApiException('Firecrawl request failed with HTTP 500: boom', 500, 'boom'));

        $this->post($client, self::COLLECTION, '{"url": "https://example.org"}');

        self::assertResponseStatusCodeSame(201);
        $data = $client->getResponse()->toArray();
        self::assertSame('failed', $data['status']);
        self::assertSame('Firecrawl request failed with HTTP 500: boom', $data['errorMessage']);
        self::assertCount(1, self::rows($main));
    }

    public function testUnknownConnectionIsUnprocessable(): void
    {
        $client = $this->client();
        $main = $this->connection('main', self::MAIN);

        $this->post($client, self::COLLECTION, '{"url": "https://example.org", "apiConfiguration": "test-firecrawl-jobs-missing"}');

        self::assertResponseStatusCodeSame(422);
        self::assertSame([], self::rows($main));
        self::assertSame(0, $this->fake()->getClientCount());
    }

    public function testNoActiveConnectionIsUnprocessable(): void
    {
        $client = $this->client();
        $this->connection('old', self::MAIN, active: false);

        $this->post($client, self::COLLECTION, '{"url": "https://example.org"}');

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('No active firecrawl connection', $client->getResponse()->getContent(false));
    }

    public function testMalformedJsonIsABadRequest(): void
    {
        $client = $this->client();
        $this->connection('main', self::MAIN);

        $this->post($client, self::COLLECTION, '{"url":');

        self::assertResponseStatusCodeSame(400);
    }

    public function testMissingUrlIsUnprocessable(): void
    {
        $client = $this->client();
        $main = $this->connection('main', self::MAIN);

        $this->post($client, self::COLLECTION, '{"type": "crawl"}');

        self::assertResponseStatusCodeSame(422);
        self::assertSame([], self::rows($main));
    }

    public function testSyncCopiesStatusAndSummary(): void
    {
        $client = $this->client();
        $main = $this->connection('main', self::MAIN);
        $row = $this->row($main, 'crawl-9');
        $this->fake()->serve('GET', '/v2/crawl/crawl-9', ['status' => 'completed', 'total' => 4, 'completed' => 4, 'creditsUsed' => 4, 'data' => []], self::MAIN);

        $data = $client->request('POST', self::COLLECTION.'/'.$row->getId().'/sync', ['headers' => ['Accept' => 'application/ld+json']])->toArray();

        self::assertResponseStatusCodeSame(200);
        self::assertSame('completed', $data['status']);
        self::assertSame(['total' => 4, 'completed' => 4, 'credits_used' => 4], $data['resultSummary']);
        self::assertSame('completed', self::rows($main)['crawl-9']['status']);
    }

    public function testSyncOfABatchScrapeUsesItsEndpoint(): void
    {
        $client = $this->client();
        $main = $this->connection('main', self::MAIN);
        $row = $this->row($main, 'batch-9', FirecrawlJob::STATUS_SCRAPING, FirecrawlJob::TYPE_BATCH_SCRAPE);
        $fake = $this->fake();
        $fake->serve('GET', '/v2/batch/scrape/batch-9', ['success' => true, 'status' => 'completed', 'total' => 2, 'completed' => 2, 'creditsUsed' => 2, 'data' => []], self::MAIN);

        $data = $client->request('POST', self::COLLECTION.'/'.$row->getId().'/sync', ['headers' => ['Accept' => 'application/ld+json']])->toArray();

        self::assertResponseStatusCodeSame(200);
        self::assertSame('completed', $data['status']);
        self::assertSame(['total' => 2, 'completed' => 2, 'credits_used' => 2], $data['resultSummary']);
        self::assertSame(0, $fake->getRequestCount(null, 'GET', '/v2/crawl/batch-9'));
    }

    public function testScanMirrorsActiveCrawlsOnceAndReportsAFailingConnection(): void
    {
        $client = $this->client();
        $main = $this->connection('main', self::MAIN);
        $backup = $this->connection('backup', self::BACKUP);
        $fake = $this->fake();
        $fake->serve('GET', self::ACTIVE, ['success' => true, 'crawls' => [
            ['id' => 'crawl-1', 'teamId' => 't', 'url' => 'https://docs.example.org', 'options' => ['limit' => 5]],
            ['id' => 'crawl-2', 'teamId' => 't', 'url' => 'https://blog.example.org', 'options' => []],
        ]], self::MAIN);
        $fake->rejectCredentials(self::BACKUP);

        $this->post($client, self::COLLECTION.'/scan', '{}');

        self::assertResponseStatusCodeSame(201);
        $data = $client->getResponse()->toArray();
        self::assertStringStartsWith('/api/admin/jobs/', $data['@id']);
        self::assertSame('firecrawl_job_scan', $data['type']);
        // Nothing is scanned before the job runs.
        self::assertSame(0, $fake->getClientCount());

        $job = $this->runJob(self::pendingJobId());

        $rows = self::rows($main);
        self::assertSame(['crawl-1', 'crawl-2'], array_keys($rows));
        self::assertSame('crawl', $rows['crawl-1']['type']);
        self::assertSame('scraping', $rows['crawl-1']['status']);
        self::assertSame('https://docs.example.org', $rows['crawl-1']['url']);
        self::assertSame(['limit' => 5], json_decode((string) $rows['crawl-1']['options'], true));
        self::assertSame([], self::rows($backup));

        $byName = array_column($job->getResultData()['connections'], null, 'name');
        self::assertSame('ok', $byName[self::PREFIX.'main']['status']);
        self::assertSame(2, $byName[self::PREFIX.'main']['created']);
        self::assertSame('error', $byName[self::PREFIX.'backup']['status']);
        self::assertStringContainsString('HTTP 403', (string) $byName[self::PREFIX.'backup']['error']);

        // Second scan: nothing new, no duplicates.
        $this->post($client, self::COLLECTION.'/scan', json_encode(['apiConfiguration' => self::CONNECTIONS.'/'.$main->getId()], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES));
        self::assertResponseStatusCodeSame(201);
        $second = $this->runJob(self::pendingJobId());

        self::assertSame(0, $second->getResultData()['created']);
        self::assertSame(2, $second->getResultData()['unchanged']);
        self::assertCount(2, self::rows($main));
    }

    public function testScanFailsWhenEveryConnectionFails(): void
    {
        $client = $this->client();
        $this->connection('main', self::MAIN);
        $this->fake()->rejectCredentials();

        $this->post($client, self::COLLECTION.'/scan', '{}');
        self::assertResponseStatusCodeSame(201);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Firecrawl job scan failed.');

        $this->runJob(self::pendingJobId());
    }

    public function testCollectionFiltersByConnection(): void
    {
        $client = $this->client();
        $main = $this->connection('main', self::MAIN);
        $backup = $this->connection('backup', self::BACKUP);
        $this->row($main, 'crawl-main');
        $this->row($backup, 'crawl-backup');

        $data = $client->request('GET', self::COLLECTION.'?apiConfiguration='.rawurlencode(self::CONNECTIONS.'/'.$main->getId()), ['headers' => ['Accept' => 'application/ld+json']])->toArray();

        self::assertResponseIsSuccessful();
        self::assertSame(['crawl-main'], array_column($data['hydra:member'], 'firecrawlId'));
    }

    public function testItemIsReadableWithRoleUser(): void
    {
        $client = $this->client();
        $row = $this->row($this->connection('main', self::MAIN), 'crawl-1');

        $client->request('GET', self::COLLECTION.'/'.$row->getId(), ['headers' => [
            'Accept' => 'application/ld+json',
            TestHeaderAuthenticator::HEADER_ROLES => 'ROLE_USER',
        ]]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame('crawl-1', $client->getResponse()->toArray()['firecrawlId']);
    }

    /**
     * The queued scan job that has not run yet (runJob() completes it).
     */
    private static function pendingJobId(): string
    {
        return (string) self::dbal()->fetchOne(
            "SELECT id FROM dmstr_job WHERE type = :type AND status = 'pending'",
            ['type' => FirecrawlJob::JOB_TYPE_SCAN],
        );
    }
}
