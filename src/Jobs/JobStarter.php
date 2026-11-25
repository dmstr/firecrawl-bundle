<?php

// file generated with AI assistance: Claude Code - 2026-10-03 00:10:23 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\Jobs;

use Dmstr\ApiConfiguration\Entity\ApiConfiguration;
use Dmstr\ApiConfiguration\Security\ConfigSecrets;
use Dmstr\Firecrawl\Client\FirecrawlApiException;
use Dmstr\Firecrawl\Client\FirecrawlClientFactoryInterface;
use Dmstr\Firecrawl\Entity\Refs\FirecrawlJob;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Starts a job on a Firecrawl instance and stores its reference row
 * ({@see FirecrawlJob}). Shared by `POST /api/refs/firecrawl_jobs` and
 * `firecrawl:jobs:start`.
 *
 *  - scrape: `POST /v2/scrape` runs synchronously; the row ends `completed`
 *    with `resultSummary` = `metadata` (the page metadata) and
 *    `markdown_length`;
 *  - crawl: `POST /v2/crawl`; the row stores the remote `id` as
 *    `firecrawlId` and ends `scraping` (refresh it with {@see JobSyncer});
 *  - batch scrape: `POST /v2/batch/scrape` with the body
 *    `['urls' => $urls] + $options`; the row's `url` is the first URL, its
 *    `options` are the options sent including `urls`, the remote `id` is
 *    stored as `firecrawlId` and the row ends `scraping` like a crawl;
 *  - a {@see FirecrawlApiException} ends the row `failed` with
 *    `errorMessage`; the row is stored all the same.
 *
 * The request body of scrape and crawl is `['url' => $url] + $options`: the
 * URL argument wins over a `url` inside the options; for a batch scrape the
 * URL list wins over `urls` inside the options. The caller resolves and checks the
 * connection (FirecrawlConnectionResolver). Every start writes one info
 * line to the `firecrawl` log channel (type, URL, connection, actor, result).
 */
final class JobStarter
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly FirecrawlClientFactoryInterface $clientFactory,
        private readonly ConfigSecrets $secrets,
        private readonly LoggerInterface $firecrawlLogger,
        private readonly ?TokenStorageInterface $tokenStorage = null,
    ) {
    }

    /**
     * @param string                    $type    FirecrawlJob::TYPE_SCRAPE, TYPE_CRAWL or TYPE_BATCH_SCRAPE
     * @param string                    $url     the URL to scrape or crawl; ignored for a batch scrape (pass '')
     * @param array<string, mixed>|null $options Firecrawl request options
     * @param list<string>              $urls    the URLs of a batch scrape (non-empty); ignored otherwise
     *
     * @throws \InvalidArgumentException for an unknown type or a batch scrape without URLs
     */
    public function start(string $type, string $url, ?array $options, ApiConfiguration $connection, array $urls = []): FirecrawlJob
    {
        if (!\in_array($type, FirecrawlJob::TYPES, true)) {
            throw new \InvalidArgumentException(\sprintf('Unknown Firecrawl job type "%s"; expected one of: %s.', $type, implode(', ', FirecrawlJob::TYPES)));
        }

        $batch = FirecrawlJob::TYPE_BATCH_SCRAPE === $type;
        if ($batch) {
            $urls = array_values($urls);
            if ([] === $urls) {
                throw new \InvalidArgumentException('A batch scrape needs at least one URL.');
            }
            $url = (string) $urls[0];
            $options = ['urls' => $urls] + ($options ?? []);
            $body = $options;
        } else {
            $body = ['url' => $url] + ($options ?? []);
        }

        $job = (new FirecrawlJob($connection))
            ->setType($type)
            ->setUrl($url)
            ->setOptions($options);
        $this->entityManager->persist($job);

        try {
            $client = $this->clientFactory->create($this->secrets->decrypt($connection->getConfigJson()));
            if (FirecrawlJob::TYPE_SCRAPE === $type) {
                $result = $client->scrape($body);
                $data = \is_array($result['data'] ?? null) ? $result['data'] : [];
                $job->setStatus(FirecrawlJob::STATUS_COMPLETED);
                $job->setResultSummary([
                    'metadata' => $data['metadata'] ?? null,
                    'markdown_length' => isset($data['markdown']) ? \strlen((string) $data['markdown']) : null,
                ]);
            } else {
                $result = $batch ? $client->batchScrape($body) : $client->crawl($body);
                $id = $result['id'] ?? null;
                if (!\is_string($id) || '' === $id) {
                    throw new FirecrawlApiException(\sprintf('Firecrawl answered the %s request without a job id.', $batch ? 'batch scrape' : 'crawl'));
                }
                $job->setFirecrawlId($id);
                $job->setStatus(FirecrawlJob::STATUS_SCRAPING);
            }
        } catch (FirecrawlApiException $e) {
            $job->setStatus(FirecrawlJob::STATUS_FAILED);
            $job->setErrorMessage($e->getMessage());
        }

        $this->entityManager->flush();

        $context = [
            'type' => $type,
            'url' => $url,
            'connection' => $connection->getName(),
            'connection_id' => (string) $connection->getId(),
            'actor' => $this->actor(),
            'status' => $job->getStatus(),
            'job_id' => (string) $job->getId(),
            'firecrawl_id' => $job->getFirecrawlId(),
        ];
        if ($batch) {
            // Number of URLs; the first one is the row's url.
            $context['urls'] = \count($urls);
        }
        $this->firecrawlLogger->info('Firecrawl job started: {type} {url} on connection "{connection}" by {actor}, status {status}.', $context);

        return $job;
    }

    /**
     * Identifier of the authenticated user; `console` without a token
     * (commands, workers).
     */
    private function actor(): string
    {
        $user = $this->tokenStorage?->getToken()?->getUser();

        return null !== $user ? $user->getUserIdentifier() : 'console';
    }
}
