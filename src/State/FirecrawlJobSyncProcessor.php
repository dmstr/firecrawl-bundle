<?php

// file generated with AI assistance: Claude Code - 2026-10-03 00:10:23 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use Dmstr\Firecrawl\Entity\Refs\FirecrawlJob;
use Dmstr\Firecrawl\Jobs\JobSyncer;

/**
 * `POST /refs/firecrawl_jobs/{id}/sync`: refreshes the row read by the
 * provider through {@see JobSyncer} and returns it (200).
 *
 * @implements ProcessorInterface<FirecrawlJob, FirecrawlJob>
 */
final class FirecrawlJobSyncProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly JobSyncer $jobSyncer,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): FirecrawlJob
    {
        if (!$data instanceof FirecrawlJob) {
            throw new \InvalidArgumentException('Expected a FirecrawlJob.');
        }

        return $this->jobSyncer->sync($data);
    }
}
