<?php

// file generated with AI assistance: Claude Code - 2026-11-06 15:00:00 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\Connection;

use Dmstr\ApiConfiguration\Entity\ApiConfiguration;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The file converter the crawl sends document files to: the only active
 * connection of type `file-converter`. A profile names none, because a
 * deployment runs one converter; none or several active ones fail with a
 * message that says what to change.
 */
final class FileConverterConnectionResolver
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @throws \RuntimeException when not exactly one `file-converter` connection is active
     */
    public function resolve(): ApiConfiguration
    {
        $active = $this->entityManager->getRepository(ApiConfiguration::class)->findBy(['type' => FileConverterExtension::NAME, 'active' => true], ['name' => 'ASC'], 2);

        return match (\count($active)) {
            1 => $active[0],
            0 => throw new \RuntimeException('Converting files needs an active file-converter connection; create one under /api/admin/api_configurations, or set crawl.convertFiles to No.'),
            default => throw new \RuntimeException('More than one file-converter connection is active; deactivate all but the one the crawl should use.'),
        };
    }
}
