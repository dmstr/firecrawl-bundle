<?php

// file generated with AI assistance: Claude Code - 2026-10-03 00:10:23 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use Dmstr\Firecrawl\Connection\FirecrawlConnectionResolver;
use Dmstr\Firecrawl\Entity\Refs\FirecrawlJob;
use Dmstr\SymfonyJobQueue\Entity\Job;
use Dmstr\SymfonyJobQueue\Service\JobQueueService;
use Symfony\Component\HttpFoundation\Request;

/**
 * `POST /refs/firecrawl_jobs/scan`: validates the body and queues a job of
 * type `firecrawl_job_scan`, run by
 * {@see \Dmstr\Firecrawl\JobProcessor\FirecrawlJobScanJobProcessor}.
 *
 * JobQueueService validates only against schemas below the app's own entity
 * directory and cannot see the bundle's `FirecrawlJob/scan.input.json`, so
 * the raw body is validated here first ({@see InputBodyValidator}: 400
 * malformed JSON, 422 schema violation), and the optional connection is
 * resolved before a job exists (422 for unknown, inactive, ambiguous or
 * non-`firecrawl` connections).
 *
 * The job input is `{}` (all `firecrawl` connections) or
 * `{"apiConfiguration": "<connection uuid>"}`.
 *
 * @implements ProcessorInterface<null, Job>
 */
final class FirecrawlJobScanProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly InputBodyValidator $inputBodyValidator,
        private readonly FirecrawlConnectionResolver $connectionResolver,
        private readonly JobQueueService $jobQueueService,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Job
    {
        $request = $context['request'] ?? null;
        $body = $request instanceof Request ? $request->getContent() : '';

        $input = $this->inputBodyValidator->validate(FirecrawlJob::OPERATION_SCAN, $body);

        $jobInput = [];
        if (isset($input['apiConfiguration'])) {
            $connection = $this->connectionResolver->resolve((string) $input['apiConfiguration']);
            $jobInput['apiConfiguration'] = (string) $connection->getId();
        }

        return $this->jobQueueService->createAndDispatch(FirecrawlJob::JOB_TYPE_SCAN, $jobInput, FirecrawlJob::OPERATION_SCAN);
    }
}
