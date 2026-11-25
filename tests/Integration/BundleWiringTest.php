<?php

// file generated with AI assistance: Claude Code - 2026-11-25 20:48:51 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\Tests\Integration;

use Dmstr\ApiConfiguration\Entity\ApiConfiguration;
use Dmstr\Firecrawl\Entity\CrawlProfile;
use Dmstr\Firecrawl\Entity\Refs\FirecrawlJob;
use Dmstr\Firecrawl\Testing\FakeFirecrawlClientFactory;
use Dmstr\Firecrawl\Tests\Fixtures\InMemoryPageImporter;
use Dmstr\Firecrawl\Tests\Kernel\TestKernel;
use Dmstr\Firecrawl\Tests\Kernel\TestPermissionVoter;
use Dmstr\SymfonyJobQueue\Entity\Job;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\RouterInterface;

/**
 * Wiring of the bundle in the test kernel, without a database: every kernel
 * variant compiles, the routes and console commands exist, the host's
 * aliases of the client factory and the page importer are used, the
 * `firecrawl` log channel exists, the entities of all bundles are mapped
 * and the bundles' migrations are registered.
 */
final class BundleWiringTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function environments(): iterable
    {
        yield TestKernel::ENV_WITH_VOTER => [TestKernel::ENV_WITH_VOTER];
        yield TestKernel::ENV_NO_VOTER => [TestKernel::ENV_NO_VOTER];
    }

    #[DataProvider('environments')]
    public function testEveryKernelVariantCompiles(string $environment): void
    {
        $kernel = self::bootKernel(['environment' => $environment]);

        self::assertSame($environment, $kernel->getEnvironment());
        self::assertSame(TestKernel::ENV_WITH_VOTER === $environment, self::getContainer()->has(TestPermissionVoter::class));
    }

    public function testRoutesExist(): void
    {
        /** @var RouterInterface $router */
        $router = self::getContainer()->get('router');
        $paths = array_map(static fn ($route): string => $route->getPath(), iterator_to_array($router->getRouteCollection()));

        foreach ([
            '/api/firecrawl/scrape',
            '/api/firecrawl/map',
            '/api/firecrawl/crawl',
            '/api/firecrawl/batch_scrape',
            '/api/refs/firecrawl_jobs.{_format}',
            '/api/refs/firecrawl_jobs/{id}.{_format}',
            '/api/admin/crawl_profiles.{_format}',
            '/api/admin/api_configurations.{_format}',
            '/api/admin/jobs/{id}.{_format}',
        ] as $path) {
            self::assertContains($path, $paths);
        }
    }

    public function testConsoleCommandsAreRegistered(): void
    {
        $application = new Application(self::bootKernel());

        foreach ([
            'firecrawl:scrape', 'firecrawl:map', 'firecrawl:crawl', 'firecrawl:batch-scrape',
            'firecrawl:jobs', 'firecrawl:jobs:start', 'firecrawl:jobs:sync', 'firecrawl:jobs:scan', 'firecrawl:jobs:import',
            'firecrawl:connection:seed', 'firecrawl:file-converter:seed', 'firecrawl:crawl-profile:import',
            'app:crawl:discover', 'app:crawl:run', 'app:crawl:import',
        ] as $name) {
            self::assertTrue($application->has($name), $name);
        }
    }

    public function testTheTestSeamsOfTheHostAreUsed(): void
    {
        $container = self::getContainer();

        self::assertInstanceOf(FakeFirecrawlClientFactory::class, $container->get(FakeFirecrawlClientFactory::class));
        self::assertInstanceOf(InMemoryPageImporter::class, $container->get(InMemoryPageImporter::class));
        self::assertTrue($container->has('monolog.logger.firecrawl'));
    }

    public function testTheEntitiesOfAllBundlesAreMapped(): void
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');

        self::assertSame('ref_firecrawl_job', $entityManager->getClassMetadata(FirecrawlJob::class)->getTableName());
        self::assertSame('firecrawl_crawl_profile', $entityManager->getClassMetadata(CrawlProfile::class)->getTableName());
        self::assertSame('dmstr_api_configuration', $entityManager->getClassMetadata(ApiConfiguration::class)->getTableName());
        self::assertSame('dmstr_job', $entityManager->getClassMetadata(Job::class)->getTableName());
    }

    public function testTheBundleMigrationsAreRegistered(): void
    {
        /** @var DependencyFactory $factory */
        $factory = self::getContainer()->get('doctrine.migrations.dependency_factory');
        $paths = $factory->getConfiguration()->getMigrationDirectories();

        self::assertArrayHasKey('Dmstr\\Firecrawl\\Migrations', $paths);
        self::assertArrayHasKey('Dmstr\\ApiConfiguration\\Migrations', $paths);
        self::assertArrayHasKey('Dmstr\\SymfonyJobQueue\\Migrations', $paths);
    }
}
