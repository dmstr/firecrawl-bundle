<?php

// file generated with AI assistance: Claude Code - 2026-10-03 00:10:23 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\Jobs;

use Dmstr\ApiConfiguration\Security\ConfigSecrets;
use Dmstr\Firecrawl\Client\FirecrawlApiException;
use Dmstr\Firecrawl\Client\FirecrawlClientFactoryInterface;
use Dmstr\Firecrawl\Entity\Refs\FirecrawlJob;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Refreshes a crawl or batch scrape row ({@see FirecrawlJob}) from its
 * Firecrawl instance. Shared by `POST /api/refs/firecrawl_jobs/{id}/sync`
 * and `firecrawl:jobs:sync`.
 *
 *  - no `firecrawlId` (a scrape, or a start that failed): returned
 *    unchanged, nothing is called or flushed;
 *  - otherwise `GET /v2/batch/scrape/{id}` for a batch scrape, else
 *    `GET /v2/crawl/{id}`, on the row's connection: `status` is copied,
 *    `resultSummary` becomes `total`, `completed`, `credits_used`,
 *    `errorMessage` is cleared;
 *  - a {@see FirecrawlApiException} sets `errorMessage` and keeps status and
 *    summary.
 */
final class JobSyncer
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly FirecrawlClientFactoryInterface $clientFactory,
        private readonly ConfigSecrets $secrets,
    ) {
    }

    public function sync(FirecrawlJob $job): FirecrawlJob
    {
        $firecrawlId = $job->getFirecrawlId();
        if (null === $firecrawlId) {
            return $job;
        }

        try {
            $client = $this->clientFactory->create($this->secrets->decrypt($job->getApiConfiguration()->getConfigJson()));
            $remote = FirecrawlJob::TYPE_BATCH_SCRAPE === $job->getType()
                ? $client->getBatchScrape($firecrawlId)
                : $client->getCrawl($firecrawlId);
            $status = $remote['status'] ?? null;
            if (\is_string($status) && '' !== $status) {
                // The column holds 20 characters; Firecrawl's statuses are shorter.
                $job->setStatus(mb_substr($status, 0, 20));
            }
            $job->setResultSummary([
                'total' => $remote['total'] ?? null,
                'completed' => $remote['completed'] ?? null,
                'credits_used' => $remote['creditsUsed'] ?? null,
            ]);
            $job->setErrorMessage(null);
        } catch (FirecrawlApiException $e) {
            $job->setErrorMessage($e->getMessage());
        }

        $this->entityManager->flush();

        return $job;
    }
}
