<?php

// file generated with AI assistance: Claude Code - 2026-11-06 16:00:00 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use Dmstr\Firecrawl\Crawl\CrawlImporter;
use Dmstr\Firecrawl\Crawl\CrawlJobQueue;
use Dmstr\Firecrawl\Entity\CrawlProfile;
use Dmstr\SymfonyJobQueue\Entity\Job;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * `POST /admin/crawl_profiles/{id}/discover`, `.../crawl` and `.../import`:
 * queues the step as a background job through {@see CrawlJobQueue}. No
 * body. The import checks the profile's import section first (422).
 *
 * @implements ProcessorInterface<CrawlProfile, Job>
 */
final class CrawlProfileJobProcessor implements ProcessorInterface
{
    /** Job type by operation name. */
    private const array TYPES = [
        CrawlProfile::OPERATION_DISCOVER => CrawlJobQueue::JOB_TYPE_DISCOVER,
        CrawlProfile::OPERATION_CRAWL => CrawlJobQueue::JOB_TYPE_CRAWL,
        CrawlProfile::OPERATION_IMPORT => CrawlJobQueue::JOB_TYPE_IMPORT,
    ];

    public function __construct(
        private readonly CrawlJobQueue $queue,
        private readonly CrawlImporter $importer,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Job
    {
        if (!$data instanceof CrawlProfile) {
            throw new NotFoundHttpException('Not Found');
        }

        $type = self::TYPES[$operation->getName()];
        if (CrawlJobQueue::JOB_TYPE_IMPORT === $type) {
            // 422 now for a missing import section or an unknown collection, not later in the job.
            $this->importer->prepare($data);
        }

        return $this->queue->queue($data, $type);
    }
}
