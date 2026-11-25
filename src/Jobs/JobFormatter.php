<?php

// file generated with AI assistance: Claude Code - 2026-10-03 00:10:23 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\Jobs;

use Dmstr\Firecrawl\Entity\Refs\FirecrawlJob;

/**
 * Plain-array view of a job reference row for the console commands
 * (`firecrawl:jobs:start`, `firecrawl:jobs:sync`); field names follow the
 * API representation.
 */
final class JobFormatter
{
    /**
     * @return array<string, mixed>
     */
    public static function toArray(FirecrawlJob $job): array
    {
        $connection = $job->getApiConfiguration();

        return [
            'id' => (string) $job->getId(),
            'apiConfiguration' => ['id' => (string) $connection->getId(), 'name' => $connection->getName()],
            'type' => $job->getType(),
            'url' => $job->getUrl(),
            'options' => $job->getOptions(),
            'firecrawlId' => $job->getFirecrawlId(),
            'status' => $job->getStatus(),
            'resultSummary' => $job->getResultSummary(),
            'errorMessage' => $job->getErrorMessage(),
            'createdAt' => $job->getCreatedAt()->format(\DATE_ATOM),
            'updatedAt' => $job->getUpdatedAt()->format(\DATE_ATOM),
        ];
    }

    public static function toJson(FirecrawlJob $job): string
    {
        return json_encode(self::toArray($job), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
    }
}
