<?php

// file generated with AI assistance: Claude Code - 2026-10-03 07:34:43 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use Dmstr\Firecrawl\Entity\Refs\FirecrawlJob;
use Dmstr\Firecrawl\Import\JobImporter;
use Dmstr\SymfonyJobQueue\Entity\Job;
use Dmstr\SymfonyJobQueue\Service\JobQueueService;
use Symfony\Component\HttpFoundation\Request;

/**
 * `POST /refs/firecrawl_jobs/{id}/import`: validates the request and queues
 * a job of type `firecrawl_job_import`, run by
 * {@see \Dmstr\Firecrawl\JobProcessor\FirecrawlJobImportJobProcessor}.
 *
 * The provider reads the row (404 for an unknown id). Before a job exists:
 *  - the raw body is validated against `FirecrawlJob/import.input.json`
 *    ({@see InputBodyValidator}: 400 malformed JSON, 422 schema violation),
 *    because JobQueueService cannot see the bundle's schemas;
 *  - the row must be a `crawl` or `batch_scrape` in status `completed` with
 *    a remote id, and `deleteVanished` is rejected on a batch scrape (422,
 *    {@see JobImporter::assertImportable()});
 *  - the collection is resolved through the application's page importer
 *    (422 for an unknown collection or one holding crawler-ingested chunks,
 *    {@see JobImporter::prepare()}).
 *
 * The job input is
 * `{"firecrawlJob": "<row uuid>", "collection": "<collection uuid>", "deleteVanished": bool}`.
 *
 * @implements ProcessorInterface<FirecrawlJob, Job>
 */
final class FirecrawlJobImportProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly InputBodyValidator $inputBodyValidator,
        private readonly JobImporter $jobImporter,
        private readonly JobQueueService $jobQueueService,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Job
    {
        if (!$data instanceof FirecrawlJob) {
            throw new \InvalidArgumentException('Expected a FirecrawlJob.');
        }

        $request = $context['request'] ?? null;
        $body = $request instanceof Request ? $request->getContent() : '';

        $input = $this->inputBodyValidator->validate(FirecrawlJob::OPERATION_IMPORT, $body);
        $deleteVanished = true === ($input['deleteVanished'] ?? false);

        $this->jobImporter->assertImportable($data, $deleteVanished);
        $target = $this->jobImporter->prepare($data, (string) $input['collection']);

        return $this->jobQueueService->createAndDispatch(
            FirecrawlJob::JOB_TYPE_IMPORT,
            [
                'firecrawlJob' => (string) $data->getId(),
                'collection' => $target->collectionId,
                'deleteVanished' => $deleteVanished,
            ],
            FirecrawlJob::OPERATION_IMPORT,
        );
    }
}
