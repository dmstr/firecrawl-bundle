<?php

// file generated with AI assistance: Claude Code - 2026-10-03 00:10:23 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\JobProcessor;

use Dmstr\ApiConfiguration\Entity\ApiConfiguration;
use Dmstr\Firecrawl\Entity\Refs\FirecrawlJob;
use Dmstr\Firecrawl\Jobs\JobScanner;
use Dmstr\SymfonyJobQueue\Entity\Job;
use Dmstr\SymfonyJobQueue\Service\Job\JobProcessorInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Runs the scan of active crawls for jobs of type `firecrawl_job_scan`,
 * queued by `POST /api/refs/firecrawl_jobs/scan`.
 *
 * Job input: `{}` scans every `firecrawl` connection (inactive ones are
 * skipped), `{"apiConfiguration": "<uuid>"}` only that connection. The scan
 * summary (see {@see JobScanner}) becomes the job's `resultData`; the job
 * queue's message handler marks the job completed and flushes.
 *
 * Failure follows the job queue's contract (throwing marks the job failed,
 * keeps `resultData` and lets messenger retry): the processor throws when
 * the referenced connection no longer exists, or when every connection it
 * tried to scan failed. A partial failure completes the job; the summary
 * lists the failing connections.
 */
final class FirecrawlJobScanJobProcessor implements JobProcessorInterface
{
    public function __construct(
        private readonly JobScanner $scanner,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function supports(string $jobType): bool
    {
        return FirecrawlJob::JOB_TYPE_SCAN === $jobType;
    }

    public function process(Job $job): void
    {
        $reference = $job->getInputData()['apiConfiguration'] ?? null;

        if (null === $reference || '' === $reference) {
            $summary = $this->scanner->scanAll();
        } else {
            $summary = $this->scanner->scan([$this->connection((string) $reference)]);
        }

        $job->setResultData($summary);

        if ($summary['failed'] > 0 && 0 === $summary['scanned']) {
            $errors = array_map(
                static fn (array $connection): string => \sprintf('%s: %s', $connection['name'], $connection['error']),
                array_values(array_filter($summary['connections'], static fn (array $connection): bool => JobScanner::STATUS_ERROR === $connection['status'])),
            );

            throw new \RuntimeException('Firecrawl job scan failed. '.implode('; ', $errors));
        }
    }

    private function connection(string $id): ApiConfiguration
    {
        $connection = Uuid::isValid($id)
            ? $this->entityManager->find(ApiConfiguration::class, Uuid::fromString($id))
            : null;

        if (!$connection instanceof ApiConfiguration) {
            throw new \RuntimeException(\sprintf('Firecrawl connection "%s" does not exist (anymore).', $id));
        }

        return $connection;
    }
}
