<?php

// file generated with AI assistance: Claude Code - 2026-10-03 00:09:10 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\Tests\Integration\Passthru;

use ApiPlatform\Symfony\Bundle\Test\Client;
use Dmstr\ApiConfiguration\Entity\ApiConfiguration;
use Dmstr\Firecrawl\Testing\FakeFirecrawlClientFactory;
use Dmstr\Firecrawl\Tests\Kernel\TestDatabase;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Fixtures of the database-backed pass-through tests (ApiTestCase). Needs
 * the PostgreSQL database from `DATABASE_URL`, migrated on first use
 * ({@see TestDatabase}). Connections are created directly
 * through the entity manager with the name prefix `test-firecrawl-pt-`.
 * The tests of the implicit connection choice depend on all connections,
 * so cleanUp() empties the tables before and after each test
 * ({@see TestDatabase::purge()}), whatever other tests left behind.
 */
trait PassthruDatabaseTrait
{
    private const string PREFIX = 'test-firecrawl-pt-';
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
        TestDatabase::purge(self::dbal());
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
    private function connection(string $name, string $baseUrl, bool $active = true, ?string $apiKey = null): ApiConfiguration
    {
        $config = ['firecrawl' => true, 'type' => 'firecrawl', 'endpoint_type' => 'rest', 'base_url' => $baseUrl];
        if (null !== $apiKey) {
            $config['api_key'] = $apiKey;
        }

        $connection = (new ApiConfiguration())
            ->setName(self::PREFIX.$name)
            ->setConfigJson($config)
            ->setActive($active);
        $this->entityManager()->persist($connection);
        $this->entityManager()->flush();

        return $connection;
    }

    /**
     * Active firecrawl connections not created by this test; the implicit
     * connection choice is only testable when there are none.
     */
    private static function foreignActiveConnections(): int
    {
        return (int) self::dbal()->fetchOne(
            'SELECT COUNT(*) FROM dmstr_api_configuration WHERE type = :type AND active = true AND name NOT LIKE :prefix',
            ['type' => 'firecrawl', 'prefix' => self::PREFIX.'%'],
        );
    }

    private static function rowCount(string $table): int
    {
        return (int) self::dbal()->fetchOne(\sprintf('SELECT COUNT(*) FROM %s', $table));
    }

    /**
     * @param array<string, string> $headers
     */
    private function post(Client $client, string $path, string $body, array $headers = []): void
    {
        $client->request('POST', $path, [
            'headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/json'] + $headers,
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
