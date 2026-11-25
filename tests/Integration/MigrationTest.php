<?php

// file generated with AI assistance: Claude Code - 2026-11-25 20:48:51 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\Tests\Integration;

use Dmstr\ApiConfiguration\Entity\ApiConfiguration;
use Dmstr\Firecrawl\Entity\Refs\FirecrawlJob;
use Dmstr\Firecrawl\Tests\Kernel\TestDatabase;
use Dmstr\Firecrawl\Tests\Kernel\TestKernel;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The bundle's migrations on PostgreSQL, from an empty database and on an
 * existing installation whose host application created `ref_firecrawl_job`
 * before the bundle shipped its baseline migration.
 *
 * Every test starts from an empty database: it drops and recreates the
 * current schema, so all rows of other tests are gone, and it leaves the
 * database fully migrated for the tests that run after it. The migrations
 * run through `doctrine:migrations:migrate` in a fresh kernel each time,
 * because the migrations metadata storage remembers within one container
 * that its table exists. Needs the PostgreSQL database from `DATABASE_URL`.
 */
#[Group('database')]
final class MigrationTest extends KernelTestCase
{
    private const string TABLE = 'ref_firecrawl_job';
    private const string VERSIONS = 'doctrine_migration_versions';
    private const string BASELINE = 'Dmstr\\Firecrawl\\Migrations\\Version20261003000000';

    /** @var list<string> class names stored by applications; they never change */
    private const array MIGRATIONS = [
        self::BASELINE,
        'Dmstr\\Firecrawl\\Migrations\\Version20261003001023',
        'Dmstr\\Firecrawl\\Migrations\\Version20261105130000',
        'Dmstr\\Firecrawl\\Migrations\\Version20261106120000',
        'Dmstr\\Firecrawl\\Migrations\\Version20261106140000',
        'Dmstr\\Firecrawl\\Migrations\\Version20261106150000',
    ];

    /**
     * `ref_firecrawl_job` in its final form, as an existing installation
     * holds it: the statements of the baseline and of Version20261003001023
     * (without its DELETE), in the same order, so the catalog matches the
     * one the migrations produce.
     *
     * @var list<string>
     */
    private const array EXISTING_TABLE = [
        'CREATE TABLE ref_firecrawl_job (id UUID NOT NULL, type VARCHAR(20) NOT NULL, url TEXT NOT NULL, options JSON DEFAULT NULL, firecrawl_id VARCHAR(64) DEFAULT NULL, status VARCHAR(20) NOT NULL, result_summary JSON DEFAULT NULL, error_message TEXT DEFAULT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY(id))',
        'CREATE INDEX idx_firecrawl_job_status ON ref_firecrawl_job (status)',
        "COMMENT ON COLUMN ref_firecrawl_job.id IS '(DC2Type:uuid)'",
        "COMMENT ON COLUMN ref_firecrawl_job.created_at IS '(DC2Type:datetimetz_immutable)'",
        "COMMENT ON COLUMN ref_firecrawl_job.updated_at IS '(DC2Type:datetimetz_immutable)'",
        'ALTER TABLE ref_firecrawl_job ADD api_configuration_id UUID NOT NULL',
        "COMMENT ON COLUMN ref_firecrawl_job.api_configuration_id IS '(DC2Type:uuid)'",
        'CREATE UNIQUE INDEX uniq_ref_firecrawl_job_api_configuration_firecrawl_id ON ref_firecrawl_job (api_configuration_id, firecrawl_id) WHERE (firecrawl_id IS NOT NULL)',
        'CREATE INDEX idx_ref_firecrawl_job_api_configuration ON ref_firecrawl_job (api_configuration_id)',
        'ALTER TABLE ref_firecrawl_job ADD CONSTRAINT FK_DA018B08316D888C FOREIGN KEY (api_configuration_id) REFERENCES dmstr_api_configuration (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE',
    ];

    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    protected function setUp(): void
    {
        self::resetDatabase();
    }

    public function testAnEmptyDatabaseGetsTheWholeSchema(): void
    {
        $this->migrate();

        $executed = self::executedVersions();
        foreach (self::MIGRATIONS as $migration) {
            self::assertArrayHasKey($migration, $executed);
        }
        self::assertTrue(self::isUpToDate());

        $catalog = self::catalog();
        self::assertSame([
            ['id', 'uuid', null, 'NO', '(DC2Type:uuid)'],
            ['type', 'character varying', 20, 'NO', null],
            ['url', 'text', null, 'NO', null],
            ['options', 'json', null, 'YES', null],
            ['firecrawl_id', 'character varying', 64, 'YES', null],
            ['status', 'character varying', 20, 'NO', null],
            ['result_summary', 'json', null, 'YES', null],
            ['error_message', 'text', null, 'YES', null],
            ['created_at', 'timestamp with time zone', null, 'NO', '(DC2Type:datetimetz_immutable)'],
            ['updated_at', 'timestamp with time zone', null, 'NO', '(DC2Type:datetimetz_immutable)'],
            ['api_configuration_id', 'uuid', null, 'NO', '(DC2Type:uuid)'],
        ], array_map(
            static fn (array $column): array => [$column['column_name'], $column['data_type'], null === $column['character_maximum_length'] ? null : (int) $column['character_maximum_length'], $column['is_nullable'], $column['comment']],
            $catalog['columns'],
        ));

        $indexes = $catalog['indexes'];
        self::assertSame([
            'idx_firecrawl_job_status',
            'idx_ref_firecrawl_job_api_configuration',
            'ref_firecrawl_job_pkey',
            'uniq_ref_firecrawl_job_api_configuration_firecrawl_id',
        ], array_keys($indexes));
        self::assertStringContainsString('(status)', $indexes['idx_firecrawl_job_status']);
        self::assertStringContainsString('(api_configuration_id)', $indexes['idx_ref_firecrawl_job_api_configuration']);
        self::assertStringContainsString('UNIQUE INDEX', $indexes['uniq_ref_firecrawl_job_api_configuration_firecrawl_id']);
        self::assertStringContainsString('(api_configuration_id, firecrawl_id) WHERE (firecrawl_id IS NOT NULL)', $indexes['uniq_ref_firecrawl_job_api_configuration_firecrawl_id']);

        $constraints = $catalog['constraints'];
        self::assertSame(['fk_da018b08316d888c', 'ref_firecrawl_job_pkey'], array_keys($constraints));
        self::assertStringContainsString('FOREIGN KEY (api_configuration_id) REFERENCES dmstr_api_configuration(id) ON DELETE CASCADE', $constraints['fk_da018b08316d888c']);
        self::assertSame('PRIMARY KEY (id)', $constraints['ref_firecrawl_job_pkey']);
    }

    /**
     * An installation that created `ref_firecrawl_job` itself and has
     * recorded every migration of the bundle except the baseline, which did
     * not exist then: migrating executes exactly the baseline, which leaves
     * the table and its row alone, and records it; a second run changes
     * nothing.
     */
    public function testAnExistingTableIsKeptAndOnlyTheBaselineIsRecorded(): void
    {
        $this->migrate();
        $migrated = self::catalog();

        // The table as the host application created it, with one row.
        self::dbal()->executeStatement('DROP TABLE '.self::TABLE);
        foreach (self::EXISTING_TABLE as $sql) {
            self::dbal()->executeStatement($sql);
        }
        self::assertSame($migrated, self::catalog(), 'The existing table must equal the final form of the migrations.');
        $this->insertRow();
        self::dbal()->executeStatement('DELETE FROM '.self::VERSIONS.' WHERE version = ?', [self::BASELINE]);

        $before = self::executedVersions();
        self::assertArrayNotHasKey(self::BASELINE, $before);
        foreach (array_slice(self::MIGRATIONS, 1) as $migration) {
            self::assertArrayHasKey($migration, $before);
        }
        self::assertFalse(self::isUpToDate());
        $catalog = self::catalog();
        $rows = self::rows();
        self::assertCount(1, $rows);

        $this->migrate();

        $after = self::executedVersions();
        self::assertSame([self::BASELINE], array_keys(array_diff_key($after, $before)));
        self::assertSame($before, array_diff_key($after, [self::BASELINE => true]));
        self::assertSame($catalog, self::catalog());
        self::assertSame($rows, self::rows());
        self::assertTrue(self::isUpToDate());

        // A second run has no effect.
        $this->migrate();

        self::assertSame($after, self::executedVersions());
        self::assertSame($catalog, self::catalog());
        self::assertSame($rows, self::rows());
    }

    /**
     * Drops and recreates the current schema (normally `public`), so the
     * database is empty, and shuts the kernel down.
     */
    private static function resetDatabase(): void
    {
        $schema = (string) self::dbal()->fetchOne('SELECT current_schema()');
        $quoted = self::dbal()->quoteIdentifier($schema);
        self::dbal()->executeStatement('DROP SCHEMA '.$quoted.' CASCADE');
        self::dbal()->executeStatement('CREATE SCHEMA '.$quoted);
        static::ensureKernelShutdown();
    }

    /**
     * Runs all migrations in a fresh kernel.
     */
    private function migrate(): void
    {
        static::ensureKernelShutdown();
        TestDatabase::run(self::bootKernel());
        static::ensureKernelShutdown();
    }

    /**
     * Whether `doctrine:migrations:up-to-date` reports nothing pending; in a
     * fresh kernel.
     */
    private static function isUpToDate(): bool
    {
        static::ensureKernelShutdown();
        $tester = new CommandTester((new Application(self::bootKernel()))->find('doctrine:migrations:up-to-date'));
        $exitCode = $tester->execute([]);
        static::ensureKernelShutdown();

        return 0 === $exitCode;
    }

    private function insertRow(): void
    {
        static::ensureKernelShutdown();
        self::bootKernel();
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');

        $connection = (new ApiConfiguration())
            ->setName('test-migration-existing')
            ->setConfigJson(['firecrawl' => true, 'type' => 'firecrawl', 'endpoint_type' => 'rest', 'base_url' => 'https://firecrawl.example.org'])
            ->setActive(true);
        $row = (new FirecrawlJob($connection))
            ->setType(FirecrawlJob::TYPE_CRAWL)
            ->setUrl('https://example.org')
            ->setFirecrawlId('crawl-existing')
            ->setStatus(FirecrawlJob::STATUS_COMPLETED)
            ->setResultSummary(['total' => 1]);
        $entityManager->persist($connection);
        $entityManager->persist($row);
        $entityManager->flush();
        static::ensureKernelShutdown();
    }

    /**
     * Executed migrations, version => executed_at.
     *
     * @return array<string, mixed>
     */
    private static function executedVersions(): array
    {
        return self::dbal()->fetchAllKeyValue('SELECT version, executed_at FROM '.self::VERSIONS.' ORDER BY version');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function rows(): array
    {
        return self::dbal()->fetchAllAssociative('SELECT * FROM '.self::TABLE.' ORDER BY id');
    }

    /**
     * Structure of the table from the PostgreSQL catalog: columns in their
     * order with type, length, precision, nullability, default and comment,
     * index definitions and constraint definitions by name.
     *
     * @return array{columns: list<array<string, mixed>>, indexes: array<string, string>, constraints: array<string, string>}
     */
    private static function catalog(): array
    {
        $dbal = self::dbal();

        return [
            'columns' => $dbal->fetchAllAssociative(
                'SELECT c.ordinal_position, c.column_name, c.data_type, c.character_maximum_length, c.datetime_precision, c.is_nullable, c.column_default,
                        col_description(to_regclass(c.table_name)::oid, c.ordinal_position::int) AS comment
                 FROM information_schema.columns c
                 WHERE c.table_schema = current_schema() AND c.table_name = ?
                 ORDER BY c.ordinal_position',
                [self::TABLE],
            ),
            'indexes' => $dbal->fetchAllKeyValue(
                'SELECT indexname, indexdef FROM pg_indexes WHERE schemaname = current_schema() AND tablename = ? ORDER BY indexname',
                [self::TABLE],
            ),
            'constraints' => $dbal->fetchAllKeyValue(
                'SELECT conname, pg_get_constraintdef(oid) FROM pg_constraint WHERE conrelid = to_regclass(?) ORDER BY conname',
                [self::TABLE],
            ),
        ];
    }

    private static function dbal(): Connection
    {
        if (null === static::$kernel) {
            self::bootKernel();
        }

        /** @var Connection $connection */
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');

        return $connection;
    }
}
