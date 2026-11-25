<?php

// file generated with AI assistance: Claude Code - 2026-10-02 23:51:30 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\Tests\Unit\Seed;

use Dmstr\ApiConfiguration\Entity\ApiConfiguration;
use Dmstr\Firecrawl\Command\ConnectionSeedCommand;
use Dmstr\Firecrawl\Tests\Fixtures\FirecrawlSecrets;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Kernel-free flow of `firecrawl:connection:seed` with a stubbed entity
 * manager and validator: empty URL, create from the configured defaults,
 * no-op, update in place, validation failure, duplicates, seed file. The
 * database-backed variant is ConnectionSeedFunctionalTest.
 */
final class ConnectionSeedCommandTest extends TestCase
{
    private const string URL = 'https://firecrawl.example.org';

    public function testEmptyUrlIsANoOpWithANote(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('getRepository');
        $entityManager->expects(self::never())->method('persist');
        $entityManager->expects(self::never())->method('flush');

        $tester = $this->tester($entityManager, validations: 0, defaultBaseUrl: '  ');

        self::assertSame(Command::SUCCESS, $tester->execute([]), $tester->getDisplay());
        self::assertStringContainsString('No Firecrawl URL configured', $tester->getDisplay());
        self::assertSame(Command::SUCCESS, $tester->execute(['--url' => '']));
    }

    public function testCreatesTheDefaultConnectionFromTheConfiguredValues(): void
    {
        $persisted = [];
        $entityManager = $this->entityManager([], flushes: 1, name: 'default');
        $entityManager->method('persist')->willReturnCallback(static function (object $entity) use (&$persisted): void {
            $persisted[] = $entity;
        });

        $tester = $this->tester($entityManager, defaultBaseUrl: self::URL, defaultApiKey: 'fc-secret');

        self::assertSame(Command::SUCCESS, $tester->execute([]), $tester->getDisplay());
        self::assertCount(1, $persisted);
        $connection = $persisted[0];
        self::assertInstanceOf(ApiConfiguration::class, $connection);
        self::assertSame('default', $connection->getName());
        self::assertSame('firecrawl', $connection->getType());
        self::assertSame('rest', $connection->getEndpointType());
        self::assertTrue($connection->isActive());
        self::assertSame(ConnectionSeedCommand::buildConfig(self::URL, 'fc-secret'), $connection->getConfigJson());
        self::assertStringContainsString('created', $tester->getDisplay());
        self::assertStringNotContainsString('fc-secret', $tester->getDisplay());
    }

    public function testOptionsOverrideTheConfiguredValues(): void
    {
        $persisted = [];
        $entityManager = $this->entityManager([], flushes: 1, name: 'staging');
        $entityManager->method('persist')->willReturnCallback(static function (object $entity) use (&$persisted): void {
            $persisted[] = $entity;
        });

        $tester = $this->tester($entityManager, defaultBaseUrl: 'https://ignored.example.org', defaultApiKey: 'ignored');

        self::assertSame(Command::SUCCESS, $tester->execute(['name' => 'staging', '--url' => ' '.self::URL.' ', '--api-key' => '', '--timeout' => '50', '--no-verify-ssl' => true]), $tester->getDisplay());
        self::assertSame(ConnectionSeedCommand::buildConfig(self::URL, null, 50, false), $persisted[0]->getConfigJson());
    }

    public function testEqualConfigIsANoOp(): void
    {
        $existing = $this->existing(array_reverse(ConnectionSeedCommand::buildConfig(self::URL), true));
        $entityManager = $this->entityManager([$existing], flushes: 0);
        $entityManager->expects(self::never())->method('persist');

        $tester = $this->tester($entityManager, validations: 0);

        self::assertSame(Command::SUCCESS, $tester->execute(['--url' => self::URL]), $tester->getDisplay());
        self::assertStringContainsString('unchanged', $tester->getDisplay());
    }

    public function testEncryptedApiKeyWithTheSameValueIsUnchanged(): void
    {
        // As stored since dmstr/api-configuration-bundle 0.5: the key is encrypted.
        $stored = FirecrawlSecrets::create()->encrypt(ConnectionSeedCommand::buildConfig(self::URL, 'fc-secret'));
        self::assertStringStartsWith('enc:v1:', $stored['api_key']);
        $entityManager = $this->entityManager([$this->existing($stored)], flushes: 0);
        $entityManager->expects(self::never())->method('persist');

        $tester = $this->tester($entityManager, validations: 0);

        self::assertSame(Command::SUCCESS, $tester->execute(['--url' => self::URL, '--api-key' => 'fc-secret']), $tester->getDisplay());
        self::assertStringContainsString('unchanged', $tester->getDisplay());
        self::assertStringNotContainsString('fc-secret', $tester->getDisplay());
    }

    public function testDriftUpdatesInPlaceAndKeepsTheActiveFlag(): void
    {
        $existing = $this->existing(ConnectionSeedCommand::buildConfig('https://old.example.org'), active: false);
        $entityManager = $this->entityManager([$existing], flushes: 1);
        $entityManager->expects(self::never())->method('persist');

        $tester = $this->tester($entityManager);

        self::assertSame(Command::SUCCESS, $tester->execute(['--url' => self::URL, '--api-key' => 'fc-secret']), $tester->getDisplay());
        self::assertSame(ConnectionSeedCommand::buildConfig(self::URL, 'fc-secret'), $existing->getConfigJson());
        self::assertFalse($existing->isActive());
        self::assertStringContainsString('updated', $tester->getDisplay());
    }

    public function testViolationsAreReportedAndNothingIsWritten(): void
    {
        $entityManager = $this->entityManager([], flushes: 0);
        $entityManager->expects(self::never())->method('persist');
        $violations = new ConstraintViolationList([
            new ConstraintViolation('The string should match pattern: ^https?://', null, [], null, 'configJson', null),
        ]);

        $tester = $this->tester($entityManager, $violations);

        self::assertSame(Command::FAILURE, $tester->execute(['--url' => 'ftp://firecrawl.example.org']));
        self::assertStringContainsString('configJson: The string should match pattern', $tester->getDisplay());
    }

    public function testRejectedUpdateIsDroppedFromTheManagedEntity(): void
    {
        $existing = $this->existing(ConnectionSeedCommand::buildConfig('https://old.example.org'));
        $entityManager = $this->entityManager([$existing], flushes: 0);
        $entityManager->expects(self::once())->method('refresh')->with($existing);
        $violations = new ConstraintViolationList([
            new ConstraintViolation('Invalid URL', null, [], null, 'configJson', null),
        ]);

        $tester = $this->tester($entityManager, $violations);

        self::assertSame(Command::FAILURE, $tester->execute(['--url' => 'ftp://firecrawl.example.org']));
    }

    public function testDuplicateNamesAreAnError(): void
    {
        $config = ConnectionSeedCommand::buildConfig(self::URL);
        $entityManager = $this->entityManager([$this->existing($config), $this->existing($config)], flushes: 0);

        $tester = $this->tester($entityManager, validations: 0);

        self::assertSame(Command::FAILURE, $tester->execute(['--url' => self::URL]));
        self::assertStringContainsString('resolve the duplicates', $tester->getDisplay());
    }

    public function testNonIntegerTimeoutFailsBeforeAnyLookup(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('getRepository');

        $tester = $this->tester($entityManager, validations: 0);

        self::assertSame(Command::FAILURE, $tester->execute(['--url' => self::URL, '--timeout' => 'ten']));
    }

    public function testSeedsTheEntriesOfTheFileAfterTheSingleConnection(): void
    {
        $file = $this->seedFile([
            ['name' => 'file-a', 'config' => ['base_url' => self::URL, 'api_key' => 'fc-secret']],
            ['name' => 'file-b', 'config' => ['base_url' => self::URL, 'timeout' => 50]],
        ]);
        $names = [];
        $repository = $this->createStub(EntityRepository::class);
        $repository->method('findBy')->willReturnCallback(static function (array $criteria) use (&$names): array {
            $names[] = $criteria['name'];

            return [];
        });
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);
        $entityManager->expects(self::exactly(3))->method('persist');
        $entityManager->expects(self::exactly(3))->method('flush');

        $tester = $this->tester($entityManager);

        self::assertSame(Command::SUCCESS, $tester->execute(['name' => 'single', '--url' => self::URL, '--file' => $file]), $tester->getDisplay());
        self::assertSame(['single', 'file-a', 'file-b'], $names);
        self::assertStringNotContainsString('fc-secret', $tester->getDisplay());
    }

    public function testFileWithoutUrlSkipsTheSingleConnectionWithoutANote(): void
    {
        $file = $this->seedFile([['name' => 'file-a', 'config' => ['base_url' => self::URL]]]);
        $entityManager = $this->entityManager([], flushes: 1, name: 'file-a');

        $tester = $this->tester($entityManager);

        self::assertSame(Command::SUCCESS, $tester->execute(['--file' => $file]), $tester->getDisplay());
        self::assertStringNotContainsString('No Firecrawl URL configured', $tester->getDisplay());
        self::assertStringContainsString('"file-a" created', $tester->getDisplay());
    }

    public function testAFailingEntryDoesNotStopTheOthers(): void
    {
        $file = $this->seedFile([
            ['name' => 'file-a', 'config' => ['base_url' => 'ftp://firecrawl.example.org']],
            ['name' => 'file-b', 'config' => ['base_url' => self::URL]],
        ]);
        $repository = $this->createStub(EntityRepository::class);
        $repository->method('findBy')->willReturn([]);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);
        $entityManager->expects(self::once())->method('flush');
        $validator = $this->createStub(ValidatorInterface::class);
        $validator->method('validate')->willReturnCallback(static fn (ApiConfiguration $connection): ConstraintViolationList => 'file-a' === $connection->getName()
            ? new ConstraintViolationList([new ConstraintViolation('Invalid URL', null, [], null, 'configJson', null)])
            : new ConstraintViolationList());

        $tester = new CommandTester(new ConnectionSeedCommand($entityManager, $validator, FirecrawlSecrets::create()));

        self::assertSame(Command::FAILURE, $tester->execute(['--file' => $file]));
        self::assertStringContainsString('"file-a" is invalid', $tester->getDisplay());
        self::assertStringContainsString('"file-b" created', $tester->getDisplay());
    }

    public function testMalformedOrMissingFileFailsBeforeAnyLookup(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('getRepository');

        $tester = $this->tester($entityManager, validations: 0);

        self::assertSame(Command::FAILURE, $tester->execute(['--file' => $this->seedFile([['name' => 'a', 'config' => []]])]));
        self::assertStringContainsString('"base_url" must be a non-empty string', $tester->getDisplay());
        self::assertSame(Command::FAILURE, $tester->execute(['--file' => '/nonexistent/firecrawl-connections.json']));
        self::assertStringContainsString('does not exist', $tester->getDisplay());
    }

    /**
     * Writes $entries as a seed file into a temporary file removed at the
     * end of the process.
     *
     * @param list<array<string, mixed>> $entries
     */
    private function seedFile(array $entries): string
    {
        $file = tempnam(sys_get_temp_dir(), 'firecrawl-seed-');
        self::assertIsString($file);
        file_put_contents($file, json_encode($entries, \JSON_THROW_ON_ERROR));
        register_shutdown_function(static fn () => @unlink($file));

        return $file;
    }

    /**
     * @param list<ApiConfiguration> $found
     */
    private function entityManager(array $found, int $flushes, string $name = 'default'): EntityManagerInterface&MockObject
    {
        $repository = $this->createStub(EntityRepository::class);
        $repository->method('findBy')->willReturnCallback(static function (array $criteria) use ($found, $name): array {
            self::assertSame(['type' => 'firecrawl', 'name' => $name], $criteria);

            return $found;
        });

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturnCallback(static function (string $class) use ($repository): EntityRepository {
            self::assertSame(ApiConfiguration::class, $class);

            return $repository;
        });
        $entityManager->expects(self::exactly($flushes))->method('flush');

        return $entityManager;
    }

    private function tester(
        EntityManagerInterface $entityManager,
        ?ConstraintViolationList $violations = null,
        ?int $validations = null,
        string $defaultBaseUrl = '',
        string $defaultApiKey = '',
    ): CommandTester {
        if (0 === $validations) {
            $validator = $this->createMock(ValidatorInterface::class);
            $validator->expects(self::never())->method('validate');
        } else {
            $validator = $this->createStub(ValidatorInterface::class);
            $validator->method('validate')->willReturn($violations ?? new ConstraintViolationList());
        }

        return new CommandTester(new ConnectionSeedCommand($entityManager, $validator, FirecrawlSecrets::create(), $defaultBaseUrl, $defaultApiKey));
    }

    /**
     * @param array<string, mixed> $config
     */
    private function existing(array $config, bool $active = true): ApiConfiguration
    {
        return (new ApiConfiguration())
            ->setName('default')
            ->setConfigJson($config)
            ->setActive($active);
    }
}
