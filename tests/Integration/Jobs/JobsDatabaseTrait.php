<?php

// file generated with AI assistance: Claude Code - 2026-10-03 00:10:23 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\Tests\Integration\Jobs;

use ApiPlatform\Symfony\Bundle\Test\Client;
use Dmstr\ApiConfiguration\Entity\ApiConfiguration;
use Dmstr\Firecrawl\Entity\Refs\FirecrawlJob;
use Dmstr\Firecrawl\Testing\FakeFirecrawlClientFactory;
use Dmstr\Firecrawl\Tests\Kernel\TestDatabase;
use Dmstr\SymfonyJobQueue\Entity\Job;
use Dmstr\SymfonyJobQueue\Service\Job\JobProcessorRegistry;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Fixtures of the database-backed job reference tests (ApiTestCase). Needs
 * the PostgreSQL database from `DATABASE_URL`, migrated on first use
 * ({@see TestDatabase}). Connections are
 * created directly through the entity manager with the name prefix
 * `test-firecrawl-jobs-`; cleanUp() removes them (their job reference rows
 * cascade) and every queue job of type `firecrawl_job_scan` and
 * `firecrawl_job_import`.
 */
trait JobsDatabaseTrait
{
    private const string PREFIX = 'test-firecrawl-jobs-';
    private const string COLLECTION = '/api/refs/firecrawl_jobs';
    private const string CONNECTIONS = '/api/admin/api_configurations';

    protected function setUp(): void
    {
        self::cleanUp();
    }

    protected function tearDown(): void
    {
        self::cleanUp();
        parent::tearDown();
    }

    public static function cleanUp(): void
    {
        $connection = self::dbal();
        $connection->executeStatement('DELETE FROM dmstr_job WHERE type IN (?, ?)', [FirecrawlJob::JOB_TYPE_SCAN, FirecrawlJob::JOB_TYPE_IMPORT]);
        $connection->executeStatement('DELETE FROM dmstr_api_configuration WHERE name LIKE :prefix', ['prefix' => self::PREFIX.'%']);
        static::ensureKernelShutdown();
    }

    /**
     * A client whose kernel is kept across requests, so the scripted fake
     * client factory and the entities created here stay in place.
     */
    private function client(): Client
    {
        $client = static::createClient();
        $client->disableReboot();

        return $client;
    }

    private function fake(): FakeFirecrawlClientFactory
    {
        $fake = static::getContainer()->get(FakeFirecrawlClientFactory::class);
        self::assertInstanceOf(FakeFirecrawlClientFactory::class, $fake);

        return $fake;
    }

    private function entityManager(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');

        return $entityManager;
    }

    /**
     * @param string $name without the prefix
     */
    private function connection(string $name, string $baseUrl, bool $active = true): ApiConfiguration
    {
        $connection = (new ApiConfiguration())
            ->setName(self::PREFIX.$name)
            ->setConfigJson(['firecrawl' => true, 'type' => 'firecrawl', 'endpoint_type' => 'rest', 'base_url' => $baseUrl])
            ->setActive($active);
        $this->entityManager()->persist($connection);
        $this->entityManager()->flush();

        return $connection;
    }

    private function row(ApiConfiguration $connection, ?string $firecrawlId, string $status = FirecrawlJob::STATUS_SCRAPING, string $type = FirecrawlJob::TYPE_CRAWL): FirecrawlJob
    {
        $row = (new FirecrawlJob($connection))
            ->setType($type)
            ->setUrl('https://example.org')
            ->setFirecrawlId($firecrawlId)
            ->setStatus($status);
        $this->entityManager()->persist($row);
        $this->entityManager()->flush();

        return $row;
    }

    /**
     * Job reference rows of a connection, straight from the database.
     *
     * @return array<string, array<string, mixed>> keyed by firecrawl_id
     */
    private static function rows(ApiConfiguration $connection): array
    {
        $rows = self::dbal()->fetchAllAssociative(
            'SELECT * FROM ref_firecrawl_job WHERE api_configuration_id = :id ORDER BY firecrawl_id',
            ['id' => (string) $connection->getId()],
        );

        return array_column($rows, null, 'firecrawl_id');
    }

    /**
     * Runs a queued scan job the way the job queue's message handler does
     * (tests route `async` to an in-memory transport, so no worker runs).
     */
    private function runJob(string $jobId): Job
    {
        $job = $this->entityManager()->find(Job::class, $jobId);
        self::assertInstanceOf(Job::class, $job);

        /** @var JobProcessorRegistry $registry */
        $registry = static::getContainer()->get(JobProcessorRegistry::class);
        $registry->getProcessor($job->getType())->process($job);
        $job->setStatus('completed');
        $this->entityManager()->flush();

        return $job;
    }

    /**
     * @param array<string, string> $headers
     */
    private function post(Client $client, string $path, string $body, array $headers = []): void
    {
        $client->request('POST', $path, [
            'headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/ld+json'] + $headers,
            'body' => $body,
        ]);
    }

    private static function dbal(): Connection
    {
        if (null === static::$kernel) {
            static::bootKernel();
        }
        TestDatabase::migrate(static::$kernel);

        /** @var Connection $connection */
        $connection = static::getContainer()->get('doctrine.dbal.default_connection');

        return $connection;
    }
}
