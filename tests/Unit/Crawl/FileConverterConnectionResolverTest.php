<?php

// file generated with AI assistance: Claude Code - 2026-11-06 15:00:00 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\Tests\Unit\Crawl;

use Dmstr\ApiConfiguration\Entity\ApiConfiguration;
use Dmstr\Firecrawl\Connection\FileConverterConnectionResolver;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;

/**
 * Kernel-free: the crawl's file converter is the only active
 * `file-converter` connection; none or several fail with what to change.
 */
final class FileConverterConnectionResolverTest extends TestCase
{
    public function testTheOnlyActiveConnectionIsUsed(): void
    {
        $connection = new ApiConfiguration();

        self::assertSame($connection, self::resolver([$connection])->resolve());
    }

    public function testNoneFails(): void
    {
        $this->expectExceptionMessage('needs an active file-converter connection');
        self::resolver([])->resolve();
    }

    public function testSeveralFail(): void
    {
        $this->expectExceptionMessage('More than one file-converter connection is active');
        self::resolver([new ApiConfiguration(), new ApiConfiguration()])->resolve();
    }

    /**
     * @param list<ApiConfiguration> $active
     */
    private static function resolver(array $active): FileConverterConnectionResolver
    {
        $repository = self::createStub(EntityRepository::class);
        $repository->method('findBy')->willReturn($active);
        $entityManager = self::createStub(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);

        return new FileConverterConnectionResolver($entityManager);
    }
}
