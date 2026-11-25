<?php

// file generated with AI assistance: Claude Code - 2026-10-03 07:31:47 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\Import;

use Dmstr\Firecrawl\Client\FirecrawlApiException;
use Dmstr\Firecrawl\Client\FirecrawlClientInterface;
use Dmstr\Firecrawl\Entity\Refs\FirecrawlJob;

/**
 * Reads the result of a completed Firecrawl crawl or batch scrape, one
 * result page at a time.
 *
 * {@see open()} fetches the status page (`GET /v2/crawl/{id}` or
 * `GET /v2/batch/scrape/{id}`) and exposes `status`, `total`, `completed`
 * and `expiresAt`; {@see pages()} then yields the entries of that first
 * result page and follows the top-level `next` link through
 * {@see FirecrawlClientInterface::getUrl()}, fetching the next result page
 * only when the previous one has been consumed. The reader never holds more
 * than one result page.
 *
 * Every `data[]` entry becomes either a {@see CrawledPage} (Markdown after
 * {@see SetextHeadingNormalizer}, URL after {@see UrlNormalizer} with
 * `metadata.sourceURL` before `metadata.url`, title from `metadata.title`,
 * status code from `metadata.statusCode`) or a {@see RejectedPage} (no
 * Markdown, no usable source URL, normalised URL longer than
 * {@see UrlNormalizer::MAX_LENGTH}).
 *
 * Unrecoverable failures throw {@see ResultReaderException}:
 *  - the remote status is not `completed`;
 *  - the result expired or is unknown on the instance: HTTP 404, a 2xx body
 *    with `success: false`, or `expiresAt` in the past (checked on the
 *    status page before anything else is fetched); also between result
 *    pages, naming the page number. Firecrawl does not document the shape
 *    of an expired result, so these are heuristics;
 *  - a `next` link whose path is not this job's own result endpoint
 *    (`/v2/crawl/{id}` or `/v2/batch/scrape/{id}` with this remote id, any
 *    query), or a link that repeats. Host and scheme of `next` are not
 *    checked: the client replaces them with the connection's own (SSRF
 *    guard), and Firecrawl is known to emit `next` with `http://`.
 *
 * Other {@see FirecrawlApiException}s (transport failures, HTTP 5xx, a
 * non-JSON 2xx body) are not wrapped and may be retried.
 */
final class ResultReader
{
    private ?string $status = null;
    private ?int $total = null;
    private ?int $completed = null;
    private ?\DateTimeImmutable $expiresAt = null;

    /** @var array<string, mixed>|null the status page until pages() consumes it */
    private ?array $firstPage = null;

    private int $resultPages = 0;
    private bool $finished = false;

    private function __construct(
        private readonly FirecrawlClientInterface $client,
        private readonly string $type,
        private readonly string $remoteId,
    ) {
    }

    /**
     * Fetches the status page and checks status and expiry.
     *
     * @param string $type FirecrawlJob::TYPE_CRAWL or FirecrawlJob::TYPE_BATCH_SCRAPE
     *
     * @throws ResultReaderException  not completed, expired or unknown
     * @throws FirecrawlApiException  transport or other HTTP failure
     * @throws \InvalidArgumentException for another job type or an empty remote id
     */
    public static function open(FirecrawlClientInterface $client, string $type, string $remoteId, ?\DateTimeImmutable $now = null): self
    {
        if (FirecrawlJob::TYPE_CRAWL !== $type && FirecrawlJob::TYPE_BATCH_SCRAPE !== $type) {
            throw new \InvalidArgumentException(\sprintf('Firecrawl jobs of type "%s" have no result to read; expected crawl or batch_scrape.', $type));
        }
        if ('' === $remoteId) {
            throw new \InvalidArgumentException('The Firecrawl job has no remote id.');
        }

        $reader = new self($client, $type, $remoteId);
        $response = $reader->fetch(1, null);
        $reader->resultPages = 1;

        $status = $response['status'] ?? null;
        $reader->status = \is_string($status) ? $status : null;
        $reader->total = self::intOrNull($response['total'] ?? null);
        $reader->completed = self::intOrNull($response['completed'] ?? null);
        $reader->expiresAt = self::dateOrNull($response['expiresAt'] ?? null);

        if (FirecrawlJob::STATUS_COMPLETED !== $reader->status) {
            throw new ResultReaderException(\sprintf('Firecrawl %s %s is not completed (remote status: %s); only completed results can be imported.', $reader->label(), $remoteId, $reader->status ?? 'unknown'));
        }
        if (null !== $reader->expiresAt && $reader->expiresAt < ($now ?? new \DateTimeImmutable())) {
            throw new ResultReaderException(\sprintf('The result of Firecrawl %s %s expired at %s; start the job again.', $reader->label(), $remoteId, $reader->expiresAt->format(\DATE_ATOM)));
        }

        $reader->firstPage = $response;

        return $reader;
    }

    /**
     * Pages of the result, lazily: the next result page is fetched only
     * after every entry of the previous one has been consumed. Can be
     * iterated once.
     *
     * @return \Generator<int, CrawledPage|RejectedPage>
     *
     * @throws ResultReaderException expired or unknown between pages, foreign or repeated continuation link
     * @throws FirecrawlApiException transport or other HTTP failure
     */
    public function pages(): \Generator
    {
        if (null === $this->firstPage) {
            throw new \LogicException('The result pages can be read only once.');
        }

        $response = $this->firstPage;
        $this->firstPage = null;
        $page = 1;
        $followed = [];

        while (true) {
            $next = $response['next'] ?? null;
            $data = $response['data'] ?? [];
            // Only the entries and the link are kept while the entries are consumed.
            unset($response);

            foreach (\is_array($data) ? $data : [] as $entry) {
                yield self::page(\is_array($entry) ? $entry : []);
            }
            unset($data);

            if (!\is_string($next) || '' === $next) {
                break;
            }

            $this->assertOwnLink($next);
            if (isset($followed[$next])) {
                throw new ResultReaderException(\sprintf('Firecrawl %s %s: the continuation link of result page %d repeats an earlier one (%s).', $this->label(), $this->remoteId, $page, $next));
            }
            $followed[$next] = true;

            ++$page;
            $response = $this->fetch($page, $next);
            $this->resultPages = $page;
        }

        $this->finished = true;
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }

    /** Number of pages Firecrawl reports for the job; null when the status page has none. */
    public function getTotal(): ?int
    {
        return $this->total;
    }

    public function getCompleted(): ?int
    {
        return $this->completed;
    }

    public function getExpiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }

    /** Result pages fetched so far: 1 right after open() (the status page), plus every page fetched through `next`. */
    public function getResultPages(): int
    {
        return $this->resultPages;
    }

    /** True once pages() has run to the end without an exception. */
    public function isFinished(): bool
    {
        return $this->finished;
    }

    /**
     * Turns one `data[]` entry into a page or a rejection.
     *
     * @param array<mixed> $entry
     */
    public static function page(array $entry): CrawledPage|RejectedPage
    {
        $metadata = \is_array($entry['metadata'] ?? null) ? $entry['metadata'] : [];

        $rawUrl = null;
        foreach (['sourceURL', 'url'] as $key) {
            if (\is_string($metadata[$key] ?? null) && '' !== trim($metadata[$key])) {
                $rawUrl = trim($metadata[$key]);
                break;
            }
        }

        if (null === $rawUrl) {
            return new RejectedPage(null, 'The result entry has no source URL (metadata.sourceURL or metadata.url).');
        }

        $url = UrlNormalizer::normalize($rawUrl);
        if (null === $url) {
            return new RejectedPage($rawUrl, 'The source URL is not an absolute http(s) URL.');
        }
        if (\strlen($url) > UrlNormalizer::MAX_LENGTH) {
            return new RejectedPage(mb_strcut($url, 0, 200).'...', \sprintf('The normalised source URL is longer than %d characters.', UrlNormalizer::MAX_LENGTH));
        }

        $markdown = $entry['markdown'] ?? null;
        if (!\is_string($markdown) || '' === trim($markdown)) {
            return new RejectedPage($url, 'The result entry has no Markdown content (request the "markdown" format).');
        }

        $title = $metadata['title'] ?? null;
        if (\is_array($title)) {
            $title = array_values(array_filter($title, 'is_string'))[0] ?? null;
        }
        $title = \is_string($title) && '' !== trim($title) ? trim($title) : null;

        return new CrawledPage(
            $url,
            SetextHeadingNormalizer::normalize($markdown),
            $title,
            self::intOrNull($metadata['statusCode'] ?? null),
        );
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ResultReaderException on 404 or `success: false`
     */
    private function fetch(int $page, ?string $next): array
    {
        try {
            $response = null === $next
                ? (FirecrawlJob::TYPE_CRAWL === $this->type ? $this->client->getCrawl($this->remoteId) : $this->client->getBatchScrape($this->remoteId))
                : $this->client->getUrl($next);
        } catch (FirecrawlApiException $e) {
            if (404 === $e->getStatusCode()) {
                throw new ResultReaderException($this->expiredMessage($page, 'HTTP 404'), 0, $e);
            }

            throw $e;
        }

        if (false === ($response['success'] ?? null)) {
            $error = \is_string($response['error'] ?? null) ? ': '.$response['error'] : '';

            throw new ResultReaderException($this->expiredMessage($page, 'success: false'.$error));
        }

        return $response;
    }

    private function expiredMessage(int $page, string $cause): string
    {
        return \sprintf(
            'The result of Firecrawl %s %s expired or is unknown on the instance (result page %d, %s); start the job again and import shortly after it completes.',
            $this->label(),
            $this->remoteId,
            $page,
            $cause,
        );
    }

    /**
     * @throws ResultReaderException when the link's path is not this job's result endpoint
     */
    private function assertOwnLink(string $next): void
    {
        $path = parse_url($next, \PHP_URL_PATH);
        $expected = (FirecrawlJob::TYPE_CRAWL === $this->type ? '/v2/crawl/' : '/v2/batch/scrape/').$this->remoteId;

        if (!\is_string($path) || rawurldecode($path) !== $expected) {
            throw new ResultReaderException(\sprintf('Firecrawl %s %s: the continuation link "%s" does not belong to this job (expected the path %s).', $this->label(), $this->remoteId, $next, $expected));
        }
    }

    private function label(): string
    {
        return FirecrawlJob::TYPE_CRAWL === $this->type ? 'crawl' : 'batch scrape';
    }

    private static function intOrNull(mixed $value): ?int
    {
        if (\is_int($value)) {
            return $value;
        }
        if (\is_string($value) && 1 === preg_match('/^\d+$/', $value)) {
            return (int) $value;
        }
        if (\is_float($value) && floor($value) === $value) {
            return (int) $value;
        }

        return null;
    }

    private static function dateOrNull(mixed $value): ?\DateTimeImmutable
    {
        if (!\is_string($value) || '' === $value) {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }
}
