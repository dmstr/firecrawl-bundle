<?php

// file generated with AI assistance: Claude Code - 2026-10-03 00:10:23 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\Jobs;

use Dmstr\ApiConfiguration\Entity\ApiConfiguration;
use Dmstr\ApiConfiguration\Security\ConfigSecrets;
use Dmstr\Firecrawl\Client\FirecrawlClientFactoryInterface;
use Dmstr\Firecrawl\Client\FirecrawlProtocolException;
use Dmstr\Firecrawl\Connection\FirecrawlExtension;
use Dmstr\Firecrawl\Entity\Refs\FirecrawlJob;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Mirrors the active crawls of `firecrawl` connections into the job
 * reference table ({@see FirecrawlJob}, table `ref_firecrawl_job`).
 *
 * Per active connection, `GET /v2/crawl/active` through a client from
 * {@see FirecrawlClientFactoryInterface}; every listed crawl is looked up by
 * (connection, `firecrawlId` = crawl `id`):
 *  - missing: a row is created with `type = crawl`, the crawl's `url` and
 *    `options`, `status = scraping`;
 *  - present: counted as `unchanged`; the scan never modifies existing rows
 *    (`sync` refreshes them, see {@see JobSyncer}).
 * Only crawls can be mirrored, because Firecrawl lists only active crawls.
 * An id listed twice creates one row; a crawl without a usable `id` is
 * ignored with a warning. Inactive connections are skipped without any
 * call. When the instance cannot be listed, the failure is reported in the
 * summary and the scan continues with the next connection.
 *
 * Scan summary (JSON-serialisable, also stored as the job result):
 *
 *     [
 *         'created' => int, 'unchanged' => int,
 *         'scanned' => int, 'skipped' => int, 'failed' => int,
 *         'connections' => list<ConnectionSummary>,
 *     ]
 *
 * ConnectionSummary: `id`, `name`, `status` (`ok`, `skipped` or `error`),
 * `created`, `unchanged`, `error` (message or null) and `warnings` (list of
 * messages).
 */
final class JobScanner
{
    public const string STATUS_OK = 'ok';
    public const string STATUS_SKIPPED = 'skipped';
    public const string STATUS_ERROR = 'error';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly FirecrawlClientFactoryInterface $clientFactory,
        private readonly ConfigSecrets $secrets,
    ) {
    }

    /**
     * Scans every `firecrawl` connection, ordered by name; inactive ones
     * appear as `skipped`.
     *
     * @return array<string, mixed> scan summary, see the class docblock
     */
    public function scanAll(): array
    {
        $connections = $this->entityManager->getRepository(ApiConfiguration::class)
            ->findBy(['type' => FirecrawlExtension::NAME], ['name' => 'ASC']);

        return $this->scan($connections);
    }

    /**
     * Scans the given connections (e.g. one resolved by FirecrawlConnectionResolver).
     *
     * @param iterable<ApiConfiguration> $connections
     *
     * @return array<string, mixed> scan summary, see the class docblock
     */
    public function scan(iterable $connections): array
    {
        $summary = [
            'created' => 0,
            'unchanged' => 0,
            'scanned' => 0,
            'skipped' => 0,
            'failed' => 0,
            'connections' => [],
        ];

        foreach ($connections as $connection) {
            $result = $this->scanConnection($connection);
            $summary['connections'][] = $result;

            match ($result['status']) {
                self::STATUS_SKIPPED => ++$summary['skipped'],
                self::STATUS_ERROR => ++$summary['failed'],
                default => ++$summary['scanned'],
            };
            $summary['created'] += $result['created'];
            $summary['unchanged'] += $result['unchanged'];
        }

        return $summary;
    }

    /**
     * Scans one connection and flushes the rows it created.
     *
     * @return array<string, mixed> connection summary, see the class docblock
     */
    public function scanConnection(ApiConfiguration $connection): array
    {
        $result = [
            'id' => (string) $connection->getId(),
            'name' => $connection->getName(),
            'status' => self::STATUS_OK,
            'created' => 0,
            'unchanged' => 0,
            'error' => null,
            'warnings' => [],
        ];

        if (FirecrawlExtension::NAME !== ($connection->getConfigJson()['type'] ?? null)) {
            $result['status'] = self::STATUS_ERROR;
            $result['error'] = \sprintf('Connection "%s" is not a Firecrawl connection.', $connection->getName());

            return $result;
        }

        if (!$connection->isActive()) {
            $result['status'] = self::STATUS_SKIPPED;

            return $result;
        }

        try {
            $crawls = $this->activeCrawls($connection);
        } catch (\Exception $e) {
            $result['status'] = self::STATUS_ERROR;
            $result['error'] = self::errorMessage($e);

            return $result;
        }

        $listed = [];
        foreach ($crawls as $index => $crawl) {
            $id = \is_array($crawl) ? ($crawl['id'] ?? null) : null;
            if (!\is_string($id) || '' === trim($id)) {
                $result['warnings'][] = \sprintf('Active crawl #%d has no id and is ignored.', $index);
                continue;
            }
            if (\strlen($id) > FirecrawlJob::MAX_FIRECRAWL_ID_LENGTH) {
                $result['warnings'][] = \sprintf('Crawl id "%s…" exceeds %d characters and is ignored.', substr($id, 0, 40), FirecrawlJob::MAX_FIRECRAWL_ID_LENGTH);
                continue;
            }
            if (isset($listed[$id])) {
                $result['warnings'][] = \sprintf('Crawl "%s" is listed more than once; the first entry is used.', $id);
                continue;
            }
            $listed[$id] = $crawl;
        }

        $existing = [];
        if ([] !== $listed) {
            $rows = $this->entityManager->getRepository(FirecrawlJob::class)
                ->findBy(['apiConfiguration' => $connection, 'firecrawlId' => array_map('strval', array_keys($listed))]);
            foreach ($rows as $row) {
                $existing[(string) $row->getFirecrawlId()] = true;
            }
        }

        foreach ($listed as $id => $crawl) {
            $id = (string) $id;
            if (isset($existing[$id])) {
                ++$result['unchanged'];
                continue;
            }

            $url = $crawl['url'] ?? null;
            $options = $crawl['options'] ?? null;
            $job = (new FirecrawlJob($connection))
                ->setType(FirecrawlJob::TYPE_CRAWL)
                ->setUrl(\is_string($url) ? $url : '')
                ->setOptions(\is_array($options) && [] !== $options ? $options : null)
                ->setFirecrawlId($id)
                ->setStatus(FirecrawlJob::STATUS_SCRAPING);
            $this->entityManager->persist($job);
            ++$result['created'];
        }

        if ($result['created'] > 0) {
            $this->entityManager->flush();
        }

        return $result;
    }

    /**
     * @return list<mixed> the `crawls` entries as listed
     *
     * @throws \Exception on configuration, transport, authentication or protocol failure
     */
    private function activeCrawls(ApiConfiguration $connection): array
    {
        $response = $this->clientFactory->create($this->secrets->decrypt($connection->getConfigJson()))->activeCrawls();

        $crawls = $response['crawls'] ?? null;
        if (!\is_array($crawls) || !array_is_list($crawls)) {
            throw new FirecrawlProtocolException('Firecrawl answered GET /v2/crawl/active without a crawls list.');
        }

        return $crawls;
    }

    private static function errorMessage(\Exception $e): string
    {
        $message = trim($e->getMessage());

        return '' !== $message ? $message : $e::class;
    }
}
