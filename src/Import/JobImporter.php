<?php

// file generated with AI assistance: Claude Code - 2026-10-03 07:32:46 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\Import;

use Dmstr\ApiConfiguration\Security\ConfigSecrets;
use Dmstr\Firecrawl\Client\FirecrawlClientFactoryInterface;
use Dmstr\Firecrawl\Entity\Refs\FirecrawlJob;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Imports the result of a completed crawl or batch scrape
 * ({@see FirecrawlJob}) into a knowledge collection: the pages are read one
 * at a time through {@see ResultReader} and handed to the application's
 * {@see PageImporterInterface}, which turns each into a document. Shared by
 * the job processor of `POST /api/refs/firecrawl_jobs/{id}/import` and
 * `firecrawl:jobs:import`.
 *
 * Usage: {@see assertImportable()} and {@see prepare()} before a job
 * exists (422 for unusable input), then {@see import()} in the job.
 *
 * Report (also the job's `resultData`):
 *
 *     {created, replaced, skipped, deleted, failed, pages, site,
 *      failures: [{url, reason, document?}],
 *      deletion: 'off'|'done'|'skipped', deletionSkippedReason?}
 *
 * `pages` counts the result entries read, `failures[].document` is the IRI
 * of the document involved when known (e.g. the document already holding
 * the same content). Removal of vanished pages runs only when it is
 * requested, the job is a crawl, every result page was read and no page
 * failed; otherwise `deletion` is `skipped` with the reason. The URLs seen
 * are kept as strings only (bounded by the crawl's `limit`).
 *
 * Every import writes one info line to the `firecrawl` log channel, plus one
 * warning per failed page.
 */
final class JobImporter
{
    public const string DELETION_OFF = 'off';
    public const string DELETION_DONE = 'done';
    public const string DELETION_SKIPPED = 'skipped';

    /** IRI prefix of the application's documents, used in `failures[].document`. */
    public const string DOCUMENT_IRI_PREFIX = '/api/admin/documents/';

    public function __construct(
        private readonly PageImporterInterface $pageImporter,
        private readonly FirecrawlClientFactoryInterface $clientFactory,
        private readonly ConfigSecrets $secrets,
        private readonly LoggerInterface $firecrawlLogger,
    ) {
    }

    /**
     * The site documents of a job are attributed to: the lower-cased host of
     * a crawl's start URL; null for batch scrapes (an arbitrary URL list).
     */
    public static function site(FirecrawlJob $job): ?string
    {
        return FirecrawlJob::TYPE_CRAWL === $job->getType() ? UrlNormalizer::host($job->getUrl()) : null;
    }

    /**
     * Checks the job row before anything is queued.
     *
     * @throws UnprocessableEntityHttpException job type not crawl/batch_scrape, status not completed, no remote id,
     *                                          or `deleteVanished` on a batch scrape
     */
    public function assertImportable(FirecrawlJob $job, bool $deleteVanished): void
    {
        $type = $job->getType();
        if (FirecrawlJob::TYPE_CRAWL !== $type && FirecrawlJob::TYPE_BATCH_SCRAPE !== $type) {
            throw new UnprocessableEntityHttpException(\sprintf('Firecrawl jobs of type "%s" cannot be imported; only crawl and batch_scrape jobs have a result to import.', $type));
        }
        if (FirecrawlJob::STATUS_COMPLETED !== $job->getStatus()) {
            throw new UnprocessableEntityHttpException(\sprintf('The Firecrawl job has status "%s"; only completed jobs can be imported (refresh it with sync first).', $job->getStatus()));
        }
        if (null === $job->getFirecrawlId() || '' === $job->getFirecrawlId()) {
            throw new UnprocessableEntityHttpException('The Firecrawl job has no remote id, so there is no result to import.');
        }
        if ($deleteVanished && FirecrawlJob::TYPE_CRAWL !== $type) {
            throw new UnprocessableEntityHttpException('deleteVanished is only supported for crawls; a batch scrape is an arbitrary URL list, so a missing page means nothing.');
        }
    }

    /**
     * Resolves the target collection through the port (UUID, IRI or name).
     *
     * @throws UnprocessableEntityHttpException unknown collection, or a collection holding crawler-ingested chunks
     */
    public function prepare(FirecrawlJob $job, string $collectionReference): ImportTarget
    {
        return $this->pageImporter->prepare($collectionReference, self::site($job));
    }

    /**
     * Imports the job's result into the target.
     *
     * @param callable(int): void|null $progress receives the overall progress in percent (0 to 100), only on change
     *
     * @return array<string, mixed> the report (see the class docblock)
     *
     * @throws ImportFailedException with the partial report: the result cannot be read, it held no pages, every page
     *                               failed, or the transport or the page importer failed
     */
    public function import(FirecrawlJob $job, ImportTarget $target, bool $deleteVanished, ?callable $progress = null, ?\DateTimeImmutable $now = null): array
    {
        $report = [
            'created' => 0,
            'replaced' => 0,
            'skipped' => 0,
            'deleted' => 0,
            'failed' => 0,
            'pages' => 0,
            'site' => $target->site,
            'failures' => [],
            'deletion' => $deleteVanished ? self::DELETION_SKIPPED : self::DELETION_OFF,
        ];
        if ($deleteVanished) {
            $report['deletionSkippedReason'] = 'The result was not read completely.';
        }

        /** @var array<string, true> $seen normalised URLs of the pages read */
        $seen = [];
        $lastPercent = -1;
        $reportProgress = static function (int $percent) use ($progress, &$lastPercent): void {
            $percent = max(0, min(100, $percent));
            if (null !== $progress && $percent !== $lastPercent) {
                $lastPercent = $percent;
                $progress($percent);
            }
        };

        try {
            $client = $this->clientFactory->create($this->secrets->decrypt($job->getApiConfiguration()->getConfigJson()));
            $reader = ResultReader::open($client, $job->getType(), (string) $job->getFirecrawlId(), $now);
            $total = $reader->getTotal();

            foreach ($reader->pages() as $page) {
                $index = $report['pages'];
                ++$report['pages'];
                $overall = max($total ?? 0, $index + 1);

                if ($page instanceof RejectedPage) {
                    $this->recordFailure($report, $job, $page->url, $page->reason, null);
                } else {
                    $seen[$page->url] = true;
                    $outcome = $this->pageImporter->import(
                        $target,
                        $page,
                        static function (int $done, int $pageTotal) use ($reportProgress, $index, $overall): void {
                            if ($pageTotal > 0) {
                                $reportProgress(intdiv(($index * $pageTotal + min($done, $pageTotal)) * 100, $overall * $pageTotal));
                            }
                        },
                    );

                    if ($outcome->isFailed()) {
                        $this->recordFailure($report, $job, $page->url, (string) $outcome->reason, $outcome->documentId);
                    } else {
                        ++$report[$outcome->status];
                    }
                }

                $reportProgress(intdiv(($index + 1) * 100, $overall));
            }
        } catch (ResultReaderException $e) {
            $this->logFailure($job, $target, $report, $e->getMessage());

            throw new ImportFailedException($e->getMessage(), $report, true, $e);
        } catch (\Throwable $e) {
            $message = \sprintf('Import of Firecrawl job %s aborted after %d page(s): %s', $job->getId(), $report['pages'], $e->getMessage());
            $this->logFailure($job, $target, $report, $message);

            throw new ImportFailedException($message, $report, false, $e);
        }

        if (0 === $report['pages']) {
            $message = \sprintf('The result of Firecrawl job %s holds no pages; nothing was imported.', $job->getId());
            $this->logFailure($job, $target, $report, $message);

            throw new ImportFailedException($message, $report, true);
        }

        $this->deleteVanished($report, $job, $target, $deleteVanished, $reader->isFinished(), array_keys($seen));

        if ($report['failed'] === $report['pages']) {
            $message = \sprintf('Every page of Firecrawl job %s failed to import (%d page(s)); first failure: %s', $job->getId(), $report['pages'], $report['failures'][0]['reason'] ?? 'unknown');
            $this->logFailure($job, $target, $report, $message);

            throw new ImportFailedException($message, $report, false);
        }

        $this->firecrawlLogger->info('Firecrawl job {job_id} imported into collection "{collection}": {created} created, {replaced} replaced, {skipped} skipped, {failed} failed, {deleted} deleted (deletion {deletion}).', [
            'job_id' => (string) $job->getId(),
            'firecrawl_id' => $job->getFirecrawlId(),
            'collection' => $target->collectionName,
            'collection_id' => $target->collectionId,
            'site' => $target->site,
            'created' => $report['created'],
            'replaced' => $report['replaced'],
            'skipped' => $report['skipped'],
            'failed' => $report['failed'],
            'deleted' => $report['deleted'],
            'pages' => $report['pages'],
            'deletion' => $report['deletion'],
        ]);

        return $report;
    }

    /**
     * Applies the deletion gate and removes vanished pages through the port.
     *
     * @param array<string, mixed> $report
     * @param list<string>         $seenUrls
     */
    private function deleteVanished(array &$report, FirecrawlJob $job, ImportTarget $target, bool $requested, bool $finished, array $seenUrls): void
    {
        unset($report['deletionSkippedReason']);

        if (!$requested) {
            $report['deletion'] = self::DELETION_OFF;

            return;
        }

        $reason = match (true) {
            FirecrawlJob::TYPE_CRAWL !== $job->getType() => 'Removal of vanished pages applies to crawls only.',
            !$finished => 'The result was not read completely.',
            $report['failed'] > 0 => \sprintf('%d page(s) failed; nothing is removed after an incomplete import.', $report['failed']),
            null === $target->site => 'The crawl has no site (its start URL has no host).',
            default => null,
        };

        if (null !== $reason) {
            $report['deletion'] = self::DELETION_SKIPPED;
            $report['deletionSkippedReason'] = $reason;

            return;
        }

        $report['deleted'] = $this->pageImporter->deleteVanished($target, $seenUrls);
        $report['deletion'] = self::DELETION_DONE;
    }

    /**
     * @param array<string, mixed> $report
     */
    private function recordFailure(array &$report, FirecrawlJob $job, ?string $url, string $reason, ?string $documentId): void
    {
        ++$report['failed'];
        $failure = ['url' => $url, 'reason' => $reason];
        if (null !== $documentId && '' !== $documentId) {
            $failure['document'] = self::DOCUMENT_IRI_PREFIX.$documentId;
        }
        $report['failures'][] = $failure;

        $this->firecrawlLogger->warning('Firecrawl job {job_id}: page {url} failed to import: {reason}', [
            'job_id' => (string) $job->getId(),
            'url' => $url ?? '(none)',
            'reason' => $reason,
            'document' => $failure['document'] ?? null,
        ]);
    }

    /**
     * @param array<string, mixed> $report
     */
    private function logFailure(FirecrawlJob $job, ImportTarget $target, array $report, string $message): void
    {
        $this->firecrawlLogger->error('Firecrawl job {job_id}: import into collection "{collection}" failed: {message}', [
            'job_id' => (string) $job->getId(),
            'firecrawl_id' => $job->getFirecrawlId(),
            'collection' => $target->collectionName,
            'collection_id' => $target->collectionId,
            'message' => $message,
            'pages' => $report['pages'],
            'failed' => $report['failed'],
        ]);
    }
}
