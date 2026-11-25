<?php

// file generated with AI assistance: Claude Code - 2026-10-02 23:51:30 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\Tests\Integration\Seed;

use Dmstr\ApiConfiguration\Security\ConfigSecrets;
use Dmstr\Firecrawl\Testing\FakeFirecrawlClientFactory;
use Dmstr\Firecrawl\Tests\Kernel\TestDatabase;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `firecrawl:connection:seed` against the database: create, no-op, update in
 * place, empty URL and rejected input. Needs the PostgreSQL database from
 * `DATABASE_URL`. Rows carry the name prefix `test-firecrawl-seed-` and are
 * removed before and after each test; the fake client factory proves that
 * the seed never contacts an instance.
 */
#[Group('database')]
final class ConnectionSeedFunctionalTest extends KernelTestCase
{
    private const string PREFIX = 'test-firecrawl-seed-';
    private const string URL = 'https://seed.firecrawl.example.org';

    protected function setUp(): void
    {
        self::bootKernel();
        self::cleanUp();
    }

    protected function tearDown(): void
    {
        self::cleanUp();
        parent::tearDown();
    }

    public function testFirstRunCreatesAnActiveConnection(): void
    {
        $tester = $this->command();

        self::assertSame(Command::SUCCESS, $tester->execute(['name' => self::PREFIX.'default', '--url' => self::URL, '--api-key' => 'fc-secret']), $tester->getDisplay());
        self::assertStringContainsString('created', $tester->getDisplay());

        $rows = self::rows();
        self::assertCount(1, $rows);
        self::assertSame('firecrawl', $rows[0]['type']);
        self::assertSame('rest', $rows[0]['endpoint_type']);
        self::assertTrue((bool) $rows[0]['active']);
        self::assertEquals([
            'firecrawl' => true,
            'type' => 'firecrawl',
            'endpoint_type' => 'rest',
            'base_url' => self::URL,
            'api_key' => 'fc-secret',
        ], self::config($rows[0]));
        $this->assertNoInstanceContact();
    }

    public function testSecondRunIsANoOp(): void
    {
        $input = ['name' => self::PREFIX.'default', '--url' => self::URL, '--timeout' => '50'];
        $tester = $this->command();
        self::assertSame(Command::SUCCESS, $tester->execute($input), $tester->getDisplay());
        $before = self::rows();

        // A fresh kernel reads the row back from the database, as a second
        // deployment run would.
        self::ensureKernelShutdown();
        self::bootKernel();
        $tester = $this->command();

        self::assertSame(Command::SUCCESS, $tester->execute($input), $tester->getDisplay());
        self::assertStringContainsString('unchanged', $tester->getDisplay());
        $after = self::rows();
        self::assertCount(1, $after);
        self::assertSame($before[0]['id'], $after[0]['id']);
        self::assertSame($before[0]['updated_at'], $after[0]['updated_at']);
        $this->assertNoInstanceContact();
    }

    public function testChangedUrlUpdatesInPlace(): void
    {
        $tester = $this->command();
        self::assertSame(Command::SUCCESS, $tester->execute(['name' => self::PREFIX.'default', '--url' => self::URL]), $tester->getDisplay());
        $before = self::rows();

        self::assertSame(Command::SUCCESS, $tester->execute(['name' => self::PREFIX.'default', '--url' => 'https://moved.example.org', '--no-verify-ssl' => true]), $tester->getDisplay());
        self::assertStringContainsString('updated', $tester->getDisplay());

        $after = self::rows();
        self::assertCount(1, $after);
        self::assertSame($before[0]['id'], $after[0]['id']);
        self::assertSame('https://moved.example.org', self::config($after[0])['base_url']);
        self::assertFalse(self::config($after[0])['verify_ssl']);
        $this->assertNoInstanceContact();
    }

    public function testEmptyUrlCreatesNothing(): void
    {
        $tester = $this->command();

        self::assertSame(Command::SUCCESS, $tester->execute(['name' => self::PREFIX.'default', '--url' => ' ']), $tester->getDisplay());
        self::assertStringContainsString('No Firecrawl URL configured', $tester->getDisplay());
        self::assertSame([], self::rows());
        $this->assertNoInstanceContact();
    }

    public function testInvalidInputCreatesNothing(): void
    {
        $tester = $this->command();

        self::assertSame(Command::FAILURE, $tester->execute(['name' => self::PREFIX.'default', '--url' => 'ftp://seed.firecrawl.example.org']));
        self::assertSame(Command::FAILURE, $tester->execute(['name' => self::PREFIX.'default', '--url' => self::URL, '--timeout' => '56']));
        self::assertSame([], self::rows());
    }

    public function testInvalidUpdateKeepsTheStoredConnection(): void
    {
        $tester = $this->command();
        self::assertSame(Command::SUCCESS, $tester->execute(['name' => self::PREFIX.'default', '--url' => self::URL]), $tester->getDisplay());

        self::assertSame(Command::FAILURE, $tester->execute(['name' => self::PREFIX.'default', '--url' => 'ftp://seed.firecrawl.example.org']));

        $rows = self::rows();
        self::assertCount(1, $rows);
        self::assertSame(self::URL, self::config($rows[0])['base_url']);
        $this->assertNoInstanceContact();
    }

    private function assertNoInstanceContact(): void
    {
        $fake = static::getContainer()->get(FakeFirecrawlClientFactory::class);
        self::assertInstanceOf(FakeFirecrawlClientFactory::class, $fake);
        self::assertSame(0, $fake->getRequestCount());
        self::assertSame(0, $fake->getClientCount());
    }

    private function command(): CommandTester
    {
        $application = new Application(static::$kernel);

        return new CommandTester($application->find('firecrawl:connection:seed'));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function rows(): array
    {
        return self::dbal()->fetchAllAssociative(
            'SELECT * FROM dmstr_api_configuration WHERE name LIKE :prefix ORDER BY name',
            ['prefix' => self::PREFIX.'%'],
        );
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private static function config(array $row): array
    {
        $stored = self::storedConfig($row);
        if (isset($stored['api_key'])) {
            // dmstr/api-configuration-bundle 0.5 encrypts the secret at rest.
            self::assertStringStartsWith('enc:v1:', $stored['api_key']);
        }

        return self::getContainer()->get(ConfigSecrets::class)->decrypt($stored);
    }

    /**
     * The configJson as stored, secrets encrypted.
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private static function storedConfig(array $row): array
    {
        return json_decode((string) $row['config_json'], true, 512, \JSON_THROW_ON_ERROR);
    }

    private static function cleanUp(): void
    {
        self::dbal()->executeStatement('DELETE FROM dmstr_api_configuration WHERE name LIKE :prefix', ['prefix' => self::PREFIX.'%']);
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
