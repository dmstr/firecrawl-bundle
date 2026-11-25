<?php

// file generated with AI assistance: Claude Code - 2026-10-02 23:51:30 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\Tests\Integration\Connection;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use Dmstr\Firecrawl\Testing\FakeFirecrawlClientFactory;
use Dmstr\Firecrawl\Tests\Kernel\TestDatabase;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Functional test of `firecrawl` connections through the generic connection
 * resource of dmstr/api-configuration-bundle under
 * /api/admin/api_configurations (the test firewall authenticates as admin),
 * including the health check against the fake client factory (over REST
 * and through the console twin). Needs the PostgreSQL database from
 * `DATABASE_URL`; rows are created with the name prefix `test-firecrawl-`
 * and removed before and after each test.
 */
#[Group('database')]
final class FirecrawlConnectionResourceTest extends ApiTestCase
{
    protected static ?bool $alwaysBootKernel = true;

    private const string COLLECTION = '/api/admin/api_configurations';
    private const string PREFIX = 'test-firecrawl-';
    private const string BASE_URL = 'https://firecrawl.example.org';
    private const string ACTIVE = '/v2/crawl/active';

    private const array CONFIG = [
        'firecrawl' => true,
        'type' => 'firecrawl',
        'endpoint_type' => 'rest',
        'base_url' => self::BASE_URL,
    ];

    /** A connection of another type, the bundle's `file-converter` */
    private const array OTHER_TYPE_CONFIG = [
        'file-converter' => true,
        'type' => 'file-converter',
        'endpoint_type' => 'rest',
        'base_url' => 'https://converter.example.org',
    ];

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
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function validConfigs(): iterable
    {
        yield 'minimal' => [self::CONFIG];
        yield 'full' => [self::CONFIG + ['api_key' => 'fc-secret', 'timeout' => 55, 'verify_ssl' => false]];
    }

    /**
     * @param array<string, mixed> $config
     */
    #[DataProvider('validConfigs')]
    public function testAdminCreatesFirecrawlConnection(array $config): void
    {
        $response = $this->post(static::createClient(), self::PREFIX.'default', $config);

        self::assertResponseStatusCodeSame(201);
        $data = $response->toArray();
        self::assertSame(self::PREFIX.'default', $data['name']);
        self::assertSame('firecrawl', $data['type']);
        self::assertSame('rest', $data['endpointType']);
        self::assertTrue($data['active']);
        self::assertSame(self::BASE_URL, $data['configJson']['base_url']);
        self::assertSame(1, self::rowCount());
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidConfigs(): iterable
    {
        $withoutBaseUrl = self::CONFIG;
        unset($withoutBaseUrl['base_url']);

        yield 'missing base_url' => [$withoutBaseUrl];
        yield 'non-http url' => [['base_url' => 'ftp://firecrawl.example.org'] + self::CONFIG];
        yield 'unknown property' => [self::CONFIG + ['headers' => ['X-Api-Key' => 'secret']]];
        yield 'timeout above the cap' => [self::CONFIG + ['timeout' => 56]];
    }

    /**
     * @param array<string, mixed> $config
     */
    #[DataProvider('invalidConfigs')]
    public function testInvalidConfigurationIsRejected(array $config): void
    {
        $response = $this->post(static::createClient(), self::PREFIX.'default', $config);

        self::assertResponseStatusCodeSame(422);
        self::assertContains('configJson', self::violationPaths($response));
        self::assertSame(0, self::rowCount());
    }

    public function testDuplicateFirecrawlNameIsRejectedOnName(): void
    {
        $client = static::createClient();
        $this->post($client, self::PREFIX.'default', self::CONFIG);
        self::assertResponseStatusCodeSame(201);

        $response = $this->post($client, self::PREFIX.'default', ['base_url' => 'https://other.example.org'] + self::CONFIG);

        self::assertResponseStatusCodeSame(422);
        self::assertContains('name', self::violationPaths($response));
        self::assertSame(1, self::rowCount());
    }

    public function testSameNameAsAConnectionOfAnotherTypeIsAllowed(): void
    {
        $client = static::createClient();
        $this->post($client, self::PREFIX.'shared', self::OTHER_TYPE_CONFIG);
        self::assertResponseStatusCodeSame(201);

        $this->post($client, self::PREFIX.'shared', self::CONFIG);

        self::assertResponseStatusCodeSame(201);
        self::assertSame(2, self::rowCount());
    }

    public function testRenamingKeepsUniqueness(): void
    {
        $client = static::createClient();
        $this->post($client, self::PREFIX.'default', self::CONFIG);
        $other = $this->post($client, self::PREFIX.'staging', self::CONFIG)->toArray();

        // Saving the connection unchanged is no conflict with itself ...
        $client->request('PATCH', $other['@id'], [
            'headers' => ['Content-Type' => 'application/merge-patch+json', 'Accept' => 'application/ld+json'],
            'json' => ['active' => false],
        ]);
        self::assertResponseStatusCodeSame(200);

        // ... renaming to a free name works ...
        $client->request('PATCH', $other['@id'], [
            'headers' => ['Content-Type' => 'application/merge-patch+json', 'Accept' => 'application/ld+json'],
            'json' => ['name' => self::PREFIX.'renamed'],
        ]);
        self::assertResponseStatusCodeSame(200);

        // ... taking the name of another firecrawl connection does not.
        $response = $client->request('PATCH', $other['@id'], [
            'headers' => ['Content-Type' => 'application/merge-patch+json', 'Accept' => 'application/ld+json'],
            'json' => ['name' => self::PREFIX.'default'],
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertContains('name', self::violationPaths($response));
    }

    public function testApiKeyIsStoredEncryptedReturnedMaskedAndKeptWhenOmitted(): void
    {
        $client = static::createClient();
        $created = $this->post($client, self::PREFIX.'default', self::CONFIG + ['api_key' => 'fc-clear-secret']);

        self::assertResponseStatusCodeSame(201);
        self::assertSame('********', $created->toArray()['configJson']['api_key']);
        self::assertStringNotContainsString('fc-clear-secret', $created->getContent());
        $stored = self::storedConfig($created->toArray()['id'])['api_key'];
        self::assertStringStartsWith('enc:v1:', $stored);

        $client->request('PATCH', $created->toArray()['@id'], [
            'headers' => ['Content-Type' => 'application/merge-patch+json', 'Accept' => 'application/ld+json'],
            'json' => ['configJson' => self::CONFIG + ['timeout' => 10]],
        ]);
        self::assertResponseStatusCodeSame(200);
        self::assertSame($stored, self::storedConfig($created->toArray()['id'])['api_key']);
    }

    public function testHealthReportsActiveCrawlsFromTheFake(): void
    {
        $client = static::createClient();
        // One kernel for all requests, so the scripted fake stays in place.
        $client->disableReboot();
        $id = $this->post($client, self::PREFIX.'default', self::CONFIG + ['api_key' => 'fc-secret'])->toArray()['id'];

        $fake = $this->fake();
        $fake->serve('GET', self::ACTIVE, ['success' => true, 'crawls' => [
            ['id' => 'job-1', 'teamId' => 'team', 'url' => 'https://example.org', 'options' => []],
        ]]);

        $data = $this->health($client, $id);

        self::assertResponseIsSuccessful();
        self::assertSame('ok', $data['status']);
        self::assertTrue($data['authenticated']);
        self::assertSame(self::BASE_URL, $data['endpoint']);
        self::assertSame('Firecrawl reachable, 1 active crawl(s).', $data['message']);
        self::assertSame(1, $data['metadata']['custom']['activeCrawls']);
        self::assertSame(1, $fake->getRequestCount(self::BASE_URL, 'GET', self::ACTIVE));
        self::assertSame('fc-secret', $fake->getCreatedConfigs()[0]['api_key']);

        $output = $this->healthCommand($id, 0);
        self::assertMatchesRegularExpression('/ActiveCrawls\s+1\b/', $output);
    }

    public function testHealthReportsRejectedCredentials(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $id = $this->post($client, self::PREFIX.'default', self::CONFIG + ['api_key' => 'wrong'])->toArray()['id'];
        $this->fake()->rejectCredentials();

        $data = $this->health($client, $id);

        self::assertResponseIsSuccessful();
        self::assertSame('error', $data['status']);
        self::assertFalse($data['authenticated']);
        self::assertStringStartsWith('Firecrawl rejected the credentials: ', $data['message']);
        self::assertSame('UNAUTHENTICATED', $data['error']['code']);
        self::assertMatchesRegularExpression('/Code\s+UNAUTHENTICATED\b/', $this->healthCommand($id, 1));
    }

    /**
     * The configJson as stored in the database.
     *
     * @return array<string, mixed>
     */
    public static function storedConfig(string $id): array
    {
        return json_decode((string) self::connection()->fetchOne('SELECT config_json FROM dmstr_api_configuration WHERE id = :id', ['id' => $id]), true, flags: \JSON_THROW_ON_ERROR);
    }

    public static function rowCount(): int
    {
        return (int) self::connection()->fetchOne('SELECT COUNT(*) FROM dmstr_api_configuration WHERE name LIKE :prefix', ['prefix' => self::PREFIX.'%']);
    }

    public static function cleanUp(): void
    {
        self::connection()->executeStatement('DELETE FROM dmstr_api_configuration WHERE name LIKE :prefix', ['prefix' => self::PREFIX.'%']);
        static::ensureKernelShutdown();
    }

    private static function connection(): Connection
    {
        if (null === static::$kernel) {
            static::bootKernel();
        }
        TestDatabase::migrate(static::$kernel);

        /** @var Connection $connection */
        $connection = static::getContainer()->get('doctrine.dbal.default_connection');

        return $connection;
    }

    private function fake(): FakeFirecrawlClientFactory
    {
        $fake = static::getContainer()->get(FakeFirecrawlClientFactory::class);
        self::assertInstanceOf(FakeFirecrawlClientFactory::class, $fake);

        return $fake;
    }

    /**
     * The REST health route; the nested `metadata` and `error` arrays keep
     * their keys in jsonld.
     *
     * @return array<string, mixed>
     */
    private function health(Client $client, string $id): array
    {
        return $client->request('GET', self::COLLECTION.'/'.$id.'/health', ['headers' => ['Accept' => 'application/ld+json']])->toArray();
    }

    /**
     * The console twin `app:api-configuration:health` renders the complete
     * normalized result, including `metadata.custom` and `error.code`; it
     * runs in the client's kernel, so it sees the same scripted fake.
     */
    private function healthCommand(string $id, int $expectedExitCode): string
    {
        $tester = new CommandTester((new Application(static::$kernel))->find('app:api-configuration:health'));

        self::assertSame($expectedExitCode, $tester->execute(['id' => $id]));

        return $tester->getDisplay();
    }

    /**
     * @param array<string, mixed> $config
     */
    private function post(Client $client, string $name, array $config): ResponseInterface
    {
        return $client->request('POST', self::COLLECTION, [
            'headers' => ['Content-Type' => 'application/ld+json', 'Accept' => 'application/ld+json'],
            'json' => ['name' => $name, 'configJson' => $config, 'active' => true],
        ]);
    }

    /**
     * @return list<string>
     */
    private static function violationPaths(ResponseInterface $response): array
    {
        return array_column($response->toArray(false)['violations'] ?? [], 'propertyPath');
    }
}
