<?php

// file generated with AI assistance: Claude Code - 2026-11-05 14:30:00 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\Tests\Integration\CrawlProfile;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use Dmstr\Firecrawl\Crawl\CrawlProfileLoader;
use Dmstr\Firecrawl\Tests\Kernel\TestDatabase;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Crawl profiles under /api/admin/crawl_profiles (the test firewall
 * authenticates as admin): the encrypted password in `auth`, the rules of
 * the profile and the loader the crawl commands use. Rows are created with
 * the prefix `test-profile-` and removed before and after each test.
 */
#[Group('database')]
final class CrawlProfileResourceTest extends ApiTestCase
{
    protected static ?bool $alwaysBootKernel = true;

    private const string COLLECTION = '/api/admin/crawl_profiles';
    private const string PREFIX = 'test-profile-';
    private const array SEEDS = ['discover' => ['seeds' => ['https://site.example/'], 'allowedDomains' => 'site.example, cdn.site.example']];
    private const array AUTH = ['type' => 'yii2-form', 'loginUrl' => 'https://site.example/login', 'username' => 'bot'];

    protected function setUp(): void
    {
        self::cleanUp();
    }

    protected function tearDown(): void
    {
        self::cleanUp();
        parent::tearDown();
    }

    public function testThePasswordIsEncryptedKeptByTheMaskAndRemovedByTheCheckbox(): void
    {
        $client = static::createClient();
        $created = $this->post($client, self::PREFIX.'login', ['firecrawlConnection' => $this->connection($client, 'firecrawl')] + self::SEEDS + ['auth' => self::AUTH + ['password' => 'geheim']]);

        self::assertResponseStatusCodeSame(201);
        $data = $created->toArray();
        self::assertTrue($data['auth']['passwordSet']);
        self::assertArrayNotHasKey('passwordSet', $data);
        self::assertSame('********', $data['auth']['password']);
        self::assertArrayNotHasKey('removePassword', $data['auth']);
        $stored = (string) self::db()->fetchOne('SELECT password_encrypted FROM firecrawl_crawl_profile WHERE name = ?', [self::PREFIX.'login']);
        self::assertNotSame('', $stored);
        self::assertStringNotContainsString('geheim', $stored);

        $client->request('PATCH', $data['@id'], ['headers' => ['Content-Type' => 'application/merge-patch+json'], 'json' => ['auth' => self::AUTH + ['password' => '********', 'removePassword' => false]]]);
        self::assertResponseIsSuccessful();

        [$profile] = static::getContainer()->get(CrawlProfileLoader::class)->load(self::PREFIX.'login');
        self::assertSame('geheim', $profile->auth['password'] ?? null);
        self::assertSame(['site.example', 'cdn.site.example'], $profile->allowedDomains);

        $removed = $client->request('PATCH', $data['@id'], ['headers' => ['Content-Type' => 'application/merge-patch+json'], 'json' => ['auth' => self::AUTH + ['password' => '********', 'removePassword' => true]]]);
        self::assertFalse($removed->toArray()['auth']['passwordSet']);
        self::assertArrayNotHasKey('password', $removed->toArray()['auth']);
    }

    public function testTheRulesOfTheProfileAreChecked(): void
    {
        $client = static::createClient();
        $firecrawl = $this->connection($client, 'firecrawl');
        $converter = $this->connection($client, 'file-converter');

        $noDomains = $this->post($client, self::PREFIX.'a', ['firecrawlConnection' => $firecrawl, 'discover' => ['seeds' => ['https://site.example/']], 'auth' => self::AUTH]);
        self::assertSame(['discover.allowedDomains'], self::paths($noDomains));

        $wrongType = $this->post($client, self::PREFIX.'b', ['firecrawlConnection' => $converter] + self::SEEDS);
        self::assertSame(['firecrawlConnection'], self::paths($wrongType));

        $noSeeds = $this->post($client, self::PREFIX.'d', ['firecrawlConnection' => $firecrawl, 'discover' => ['seeds' => []]]);
        self::assertSame(['discover.seeds'], self::paths($noSeeds));

        $unknownExtension = $this->post($client, self::PREFIX.'e', ['firecrawlConnection' => $firecrawl, 'discover' => self::SEEDS['discover'] + ['fileExtensions' => ['exe']]]);
        self::assertSame(['discover.fileExtensions.0'], self::paths($unknownExtension));
    }

    public function testTheLoaderRefusesAnInactiveProfile(): void
    {
        $client = static::createClient();
        $this->post($client, self::PREFIX.'off', ['firecrawlConnection' => $this->connection($client, 'firecrawl'), 'active' => false] + self::SEEDS);
        self::assertResponseStatusCodeSame(201);

        $this->expectException(\InvalidArgumentException::class);
        static::getContainer()->get(CrawlProfileLoader::class)->load(self::PREFIX.'off');
    }

    public function testTheImportReadsAYamlProfileAndItsLoginFromTheEnvironment(): void
    {
        $this->connection(static::createClient(), 'firecrawl');
        $file = tempnam(sys_get_temp_dir(), 'profile').'.yaml';
        file_put_contents($file, "discover:\n  seeds: ['https://site.example/']\n  allowed_domains: [site.example]\n  file_extensions: [pdf]\ncrawl:\n  web:\n    excluded_tags: [nav, footer]\n  files:\n    converters: {pptx: markitdown}\nauth:\n  type: yii2-form\n  login_url: 'https://site.example/login'\n  username_env: TEST_PROFILE_USER\n  password_env: TEST_PROFILE_PASSWORD\n");
        $_SERVER['TEST_PROFILE_USER'] = 'bot';
        $_SERVER['TEST_PROFILE_PASSWORD'] = 'geheim';

        try {
            $tester = new CommandTester((new Application(static::$kernel))->find('firecrawl:crawl-profile:import'));
            $tester->execute(['file' => $file, '--name' => self::PREFIX.'yaml', '--connection' => self::PREFIX.'firecrawl']);
        } finally {
            unset($_SERVER['TEST_PROFILE_USER'], $_SERVER['TEST_PROFILE_PASSWORD']);
            unlink($file);
        }

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        [$profile, $stored] = static::getContainer()->get(CrawlProfileLoader::class)->load(self::PREFIX.'yaml');
        self::assertSame(['type' => 'yii2-form', 'login_url' => 'https://site.example/login', 'username' => 'bot', 'password' => 'geheim'], $profile->auth);
        self::assertSame(['nav', 'footer'], $profile->excludedTags);
        self::assertSame(['excludedTags' => 'nav, footer', 'converters' => [['extension' => 'pptx', 'converter' => 'markitdown']]], $stored->getCrawl());
        self::assertSame(['pdf'], $stored->getDiscover()['fileExtensions']);
        self::assertSame('markitdown', $profile->converterFor('pptx'));
    }

    public static function cleanUp(): void
    {
        self::db()->executeStatement('DELETE FROM firecrawl_crawl_profile WHERE name LIKE :prefix', ['prefix' => self::PREFIX.'%']);
        self::db()->executeStatement('DELETE FROM dmstr_api_configuration WHERE name LIKE :prefix', ['prefix' => self::PREFIX.'%']);
        static::ensureKernelShutdown();
    }

    /** IRI of a new connection of $type. */
    private function connection(Client $client, string $type): string
    {
        $config = [$type => true, 'type' => $type, 'endpoint_type' => 'rest', 'base_url' => 'http://'.$type.'.example'];

        return (string) $client->request('POST', '/api/admin/api_configurations', [
            'headers' => ['Content-Type' => 'application/ld+json'],
            'json' => ['name' => self::PREFIX.$type, 'configJson' => $config, 'active' => true],
        ])->toArray()['@id'];
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function post(Client $client, string $name, array $fields): ResponseInterface
    {
        return $client->request('POST', self::COLLECTION, [
            'headers' => ['Content-Type' => 'application/ld+json'],
            'json' => ['name' => $name] + $fields,
        ]);
    }

    /** @return list<string> */
    private static function paths(ResponseInterface $response): array
    {
        return array_column($response->toArray(false)['violations'] ?? [], 'propertyPath');
    }

    private static function db(): Connection
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
