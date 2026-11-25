<?php

// file generated with AI assistance: Claude Code - 2026-10-03 07:34:43 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\JobProcessor;

use Dmstr\Firecrawl\Entity\Refs\FirecrawlJob;
use Dmstr\Firecrawl\Import\ImportFailedException;
use Dmstr\Firecrawl\Import\JobImporter;
use Dmstr\SymfonyJobQueue\Entity\Job;
use Dmstr\SymfonyJobQueue\Service\Job\JobProcessorInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Uid\Uuid;

/**
 * Imports a Firecrawl result into a knowledge collection for jobs of type
 * `firecrawl_job_import`, queued by `POST /api/refs/firecrawl_jobs/{id}/import`.
 *
 * Job input: `{"firecrawlJob": "<uuid>", "collection": "<uuid>", "deleteVanished": bool}`.
 * The import runs through {@see JobImporter}; its report becomes the job's
 * `resultData` (also the partial report of a failed import), and the
 * progress is flushed whenever the percentage changes.
 *
 * Failure policy (the job queue's handler catches `\Exception` only and
 * rethrows for messenger retries):
 *  - unrecoverable, as {@see UnrecoverableMessageHandlingException} (no
 *    retry): the row or the collection no longer exists or is no longer
 *    importable, the result is not completed, expired or unknown on the
 *    instance, a continuation link does not belong to the job, or the
 *    result holds no pages;
 *  - recoverable, as a plain exception (messenger retries; the re-run is an
 *    upsert and skips finished pages): transport failures, failures of the
 *    application's page importer, every page failed.
 *
 * The entity manager is never cleared: the job queue's handler holds the
 * `Job` entity and flushes it after this processor returns.
 */
final class FirecrawlJobImportJobProcessor implements JobProcessorInterface
{
    public function __construct(
        private readonly JobImporter $jobImporter,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function supports(string $jobType): bool
    {
        return FirecrawlJob::JOB_TYPE_IMPORT === $jobType;
    }

    public function process(Job $job): void
    {
        $input = $job->getInputData();
        $id = (string) ($input['firecrawlJob'] ?? '');
        $collection = (string) ($input['collection'] ?? '');
        $deleteVanished = true === ($input['deleteVanished'] ?? false);

        $firecrawlJob = Uuid::isValid($id) ? $this->entityManager->find(FirecrawlJob::class, Uuid::fromString($id)) : null;
        if (!$firecrawlJob instanceof FirecrawlJob) {
            throw new UnrecoverableMessageHandlingException(\sprintf('Firecrawl job "%s" does not exist (anymore).', $id));
        }

        try {
            $this->jobImporter->assertImportable($firecrawlJob, $deleteVanished);
            $target = $this->jobImporter->prepare($firecrawlJob, $collection);
        } catch (HttpExceptionInterface $e) {
            // Unknown collection, crawler-ingested chunks, or the row changed since the job was queued.
            throw new UnrecoverableMessageHandlingException($e->getMessage(), 0, $e);
        } catch (\Error $e) {
            // An \Error would bypass the job queue's \Exception handler and leave the job running.
            throw new \RuntimeException(\sprintf('Import of Firecrawl job %s failed: %s', $id, $e->getMessage()), 0, $e);
        }

        $progress = function (int $percent) use ($job): void {
            $job->setProgress($percent);
            $this->entityManager->flush();
        };

        try {
            $report = $this->jobImporter->import($firecrawlJob, $target, $deleteVanished, $progress);
        } catch (ImportFailedException $e) {
            $job->setResultData($e->getReport() + ['error' => $e->getMessage()]);

            if ($e->isUnrecoverable()) {
                throw new UnrecoverableMessageHandlingException($e->getMessage(), 0, $e);
            }

            throw $e;
        } catch (\Throwable $e) {
            // Only reached by \Error (JobImporter wraps every exception): it would bypass the job queue's
            // \Exception handler and leave the job running.
            throw new \RuntimeException(\sprintf('Import of Firecrawl job %s failed: %s', $id, $e->getMessage()), 0, $e);
        }

        $job->setResultData($report);
    }
}
