<?php

// file generated with AI assistance: Claude Code - 2026-11-25 20:48:51 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\Tests\Integration\Access;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use Dmstr\ApiConfiguration\Entity\ApiConfiguration;
use Dmstr\Firecrawl\Entity\Refs\FirecrawlJob;
use Dmstr\Firecrawl\Testing\FakeFirecrawlClientFactory;
use Dmstr\Firecrawl\Tests\Kernel\TestDatabase;
use Dmstr\Firecrawl\Tests\Kernel\TestHeaderAuthenticator;
use Dmstr\Firecrawl\Tests\Kernel\TestKernel;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Access matrix of every operation of the job reference table and the
 * pass-through, with the permission map of the test kernel
 * ({@see TestKernel::PERMISSIONS}):
 *
 *  - reads (`GET /api/refs/firecrawl_jobs` and `/{id}`) need
 *    `firecrawl:job:read` (ROLE_USER);
 *  - the pass-through `scrape`, `map`, `crawl`, `batch_scrape` under
 *    /api/firecrawl need `firecrawl:passthru:execute`
 *    ({@see TestKernel::ROLE_FIRECRAWL_ADMIN}), `start`, `sync`, `scan` and
 *    `import` of the job reference table `firecrawl:job:write`
 *    ({@see TestKernel::ROLE_CRAWLER}); ROLE_ADMIN holds both;
 *  - anonymous requests are answered 401 by the firewall;
 *  - without a permission voter (kernel variant
 *    {@see TestKernel::ENV_NO_VOTER}) every operation answers 403, even to
 *    ROLE_ADMIN.
 *
 * A refused request never reaches Firecrawl (no fake request), queues no
 * job and writes no job reference row. Without headers the request acts as
 * the default admin identity of {@see TestHeaderAuthenticator}. Fixtures
 * (one active `firecrawl` connection, a `scraping` and a `completed` crawl
 * row with remote ids) are created through the entity manager with the
 * prefix `test-firecrawl-acc-` and removed before and after each test,
 * together with all queue jobs of type `firecrawl_job_scan` and
 * `firecrawl_job_import`. An allowed import only queues its job (the
 * in-memory transport runs nothing), so it calls no Firecrawl endpoint;
 * the host's page importer is the test kernel's in-memory one, which
 * accepts every collection reference. Needs the PostgreSQL database from
 * `DATABASE_URL`.
 */
#[Group('database')]
final class FirecrawlAccessTest extends ApiTestCase
{
    protected static ?bool $alwaysBootKernel = true;

    private const string PREFIX = 'test-firecrawl-acc-';
    private const string BASE_URL = 'https://access.fc.example.org';
    private const string COLLECTION = '/api/refs/firecrawl_jobs';
    private const string REMOTE_ID = 'acc-crawl-1';
    private const string COMPLETED_REMOTE_ID = 'acc-crawl-done';
    private const string TARGET_COLLECTION = 'handbook';

    /** Queue job types an allowed write may create. */
    private const array JOB_TYPES = [FirecrawlJob::JOB_TYPE_SCAN, FirecrawlJob::JOB_TYPE_IMPORT];

    private FakeFirecrawlClientFactory $fake;

    protected function setUp(): void
    {
        self::cleanUp();
    }

    protected function tearDown(): void
    {
        self::cleanUp();
        parent::tearDown();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function reads(): iterable
    {
        yield 'list' => ['collection'];
        yield 'item' => ['item'];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function writes(): iterable
    {
        yield 'scrape' => ['scrape'];
        yield 'map' => ['map'];
        yield 'crawl' => ['crawl'];
        yield 'batch scrape' => ['batch_scrape'];
        yield 'start' => ['start'];
        yield 'sync' => ['sync'];
        yield 'scan' => ['scan'];
        yield 'import' => ['import'];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function allOperations(): iterable
    {
        yield from self::reads();
        yield from self::writes();
    }

    #[DataProvider('allOperations')]
    public function testAnonymousIsUnauthorized(string $target): void
    {
        $this->send($target, [TestHeaderAuthenticator::HEADER_AUTH => TestHeaderAuthenticator::ANONYMOUS]);

        self::assertResponseStatusCodeSame(401);
        self::assertSame(0, $this->fake->getRequestCount());
        self::assertSame(0, self::jobCount());
    }

    #[DataProvider('reads')]
    public function testUserCanRead(string $target): void
    {
        $this->send($target, [TestHeaderAuthenticator::HEADER_ROLES => 'ROLE_USER']);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(0, $this->fake->getRequestCount());
    }

    #[DataProvider('writes')]
    public function testUserWithoutFeatureRoleCannotWrite(string $target): void
    {
        $rows = null;
        $this->send($target, [TestHeaderAuthenticator::HEADER_ROLES => 'ROLE_USER'], $rows);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->fake->getRequestCount());
        self::assertSame(0, self::jobCount());
        self::assertSame($rows, self::rowCount());
    }

    /**
     * The roles of one permission do not grant the other.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function crossedRoles(): iterable
    {
        yield 'crawler scrape' => [TestKernel::ROLE_CRAWLER, 'scrape'];
        yield 'crawler batch scrape' => [TestKernel::ROLE_CRAWLER, 'batch_scrape'];
        yield 'firecrawl admin start' => [TestKernel::ROLE_FIRECRAWL_ADMIN, 'start'];
        yield 'firecrawl admin import' => [TestKernel::ROLE_FIRECRAWL_ADMIN, 'import'];
    }

    #[DataProvider('crossedRoles')]
    public function testTheRoleOfTheOtherPermissionCannotWrite(string $role, string $target): void
    {
        $this->send($target, [TestHeaderAuthenticator::HEADER_ROLES => $role]);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->fake->getRequestCount());
        self::assertSame(0, self::jobCount());
    }

    /**
     * @return iterable<string, array{string, string, int}>
     */
    public static function allowedWrites(): iterable
    {
        foreach (['feature role' => TestKernel::ROLE_FIRECRAWL_ADMIN, 'admin' => ''] as $label => $roles) {
            yield $label.' scrape' => [$roles, 'scrape', 200];
            yield $label.' map' => [$roles, 'map', 200];
            yield $label.' crawl' => [$roles, 'crawl', 200];
            yield $label.' batch scrape' => [$roles, 'batch_scrape', 200];
        }
        foreach (['feature role' => TestKernel::ROLE_CRAWLER, 'admin' => ''] as $label => $roles) {
            yield $label.' start' => [$roles, 'start', 201];
            yield $label.' sync' => [$roles, 'sync', 200];
            yield $label.' scan' => [$roles, 'scan', 201];
            yield $label.' import' => [$roles, 'import', 201];
        }
    }

    #[DataProvider('allowedWrites')]
    public function testFeatureRoleAndAdminCanWrite(string $roles, string $target, int $status): void
    {
        $client = $this->send($target, '' === $roles ? [] : [TestHeaderAuthenticator::HEADER_ROLES => $roles]);

        self::assertResponseStatusCodeSame($status);
        self::assertSame(\in_array($target, ['scan', 'import'], true) ? 1 : 0, self::jobCount());
        if ('import' === $target) {
            $job = $client->getResponse()->toArray();
            self::assertSame(FirecrawlJob::JOB_TYPE_IMPORT, $job['type']);
            self::assertStringStartsWith('/api/admin/jobs/', $job['@id']);
            // Queued only: nothing is read from Firecrawl before the job runs.
            self::assertSame(0, $this->fake->getRequestCount());
        }
    }

    #[DataProvider('allOperations')]
    public function testWithoutVoterEveryOperationIsForbidden(string $target): void
    {
        $rows = null;
        $this->send($target, [], $rows, TestKernel::ENV_NO_VOTER);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->fake->getRequestCount());
        self::assertSame(0, self::jobCount());
        self::assertSame($rows, self::rowCount());
    }

    /**
     * Creates the fixtures, scripts the fake for every path a write could
     * call and sends the request of $target.
     *
     * @param array<string, string> $headers
     * @param int|null              $rows    set to the job reference row count before the request
     */
    private function send(string $target, array $headers, ?int &$rows = null, string $environment = TestKernel::ENV_WITH_VOTER): Client
    {
        $client = static::createClient(['environment' => $environment]);
        $client->disableReboot();

        $connection = $this->connection();
        $row = $this->row($connection);
        $completed = $this->row($connection, self::COMPLETED_REMOTE_ID, FirecrawlJob::STATUS_COMPLETED);
        $this->fake = $this->scriptFake();
        $rows = self::rowCount();

        $name = rawurlencode((string) $connection->getName());
        [$uri, $body] = match ($target) {
            'collection' => [self::COLLECTION, null],
            'item' => [self::COLLECTION.'/'.$row->getId(), null],
            'scrape', 'map', 'crawl' => ['/api/firecrawl/'.$target.'?apiConfiguration='.$name, ['url' => 'https://example.org']],
            'batch_scrape' => ['/api/firecrawl/batch_scrape?apiConfiguration='.$name, ['urls' => ['https://example.org/a']]],
            'start' => [self::COLLECTION, ['type' => 'scrape', 'url' => 'https://example.org', 'apiConfiguration' => $connection->getName()]],
            'sync' => [self::COLLECTION.'/'.$row->getId().'/sync', null],
            'scan' => [self::COLLECTION.'/scan', ['apiConfiguration' => $connection->getName()]],
            'import' => [self::COLLECTION.'/'.$completed->getId().'/import', ['collection' => self::TARGET_COLLECTION]],
        };

        $isPassthru = str_starts_with($uri, '/api/firecrawl/');
        $options = ['headers' => ['Accept' => $isPassthru ? 'application/json' : 'application/ld+json'] + $headers];
        if (null !== $body) {
            $options['headers']['Content-Type'] = 'application/json';
            $options['body'] = json_encode($body, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
        }

        $client->request(\in_array($target, ['collection', 'item'], true) ? 'GET' : 'POST', $uri, $options);

        return $client;
    }

    private function scriptFake(): FakeFirecrawlClientFactory
    {
        $fake = static::getContainer()->get(FakeFirecrawlClientFactory::class);
        self::assertInstanceOf(FakeFirecrawlClientFactory::class, $fake);

        return $fake
            ->serve('POST', '/v2/scrape', ['success' => true, 'data' => ['markdown' => '# Access', 'metadata' => ['title' => 'Access']]])
            ->serve('POST', '/v2/map', ['success' => true, 'links' => [['url' => 'https://example.org/a']]])
            ->serve('POST', '/v2/crawl', ['success' => true, 'id' => 'acc-crawl-2', 'url' => self::BASE_URL.'/v2/crawl/acc-crawl-2'])
            ->serve('POST', '/v2/batch/scrape', ['success' => true, 'id' => 'acc-batch-1', 'url' => self::BASE_URL.'/v2/batch/scrape/acc-batch-1'])
            ->serve('GET', '/v2/crawl/'.self::REMOTE_ID, ['status' => 'scraping', 'total' => 2, 'completed' => 1, 'creditsUsed' => 1, 'data' => []])
            ->serve('GET', '/v2/crawl/active', ['success' => true, 'crawls' => []]);
    }

    private function connection(): ApiConfiguration
    {
        $connection = (new ApiConfiguration())
            ->setName(self::PREFIX.'main')
            ->setConfigJson(['firecrawl' => true, 'type' => 'firecrawl', 'endpoint_type' => 'rest', 'base_url' => self::BASE_URL])
            ->setActive(true);
        $this->entityManager()->persist($connection);
        $this->entityManager()->flush();

        return $connection;
    }

    private function row(ApiConfiguration $connection, string $remoteId = self::REMOTE_ID, string $status = FirecrawlJob::STATUS_SCRAPING): FirecrawlJob
    {
        $row = (new FirecrawlJob($connection))
            ->setType(FirecrawlJob::TYPE_CRAWL)
            ->setUrl('https://example.org')
            ->setFirecrawlId($remoteId)
            ->setStatus($status);
        $this->entityManager()->persist($row);
        $this->entityManager()->flush();

        return $row;
    }

    private function entityManager(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');

        return $entityManager;
    }

    /**
     * Removes the fixture connections (their job reference rows cascade)
     * and every scan and import job.
     */
    private static function cleanUp(): void
    {
        $connection = self::dbal();
        $connection->executeStatement('DELETE FROM dmstr_job WHERE type IN (:types)', ['types' => self::JOB_TYPES], ['types' => ArrayParameterType::STRING]);
        $connection->executeStatement('DELETE FROM dmstr_api_configuration WHERE name LIKE :prefix', ['prefix' => self::PREFIX.'%']);
        static::ensureKernelShutdown();
    }

    private static function jobCount(): int
    {
        return (int) self::dbal()->fetchOne('SELECT COUNT(*) FROM dmstr_job WHERE type IN (:types)', ['types' => self::JOB_TYPES], ['types' => ArrayParameterType::STRING]);
    }

    private static function rowCount(): int
    {
        return (int) self::dbal()->fetchOne('SELECT COUNT(*) FROM ref_firecrawl_job');
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
