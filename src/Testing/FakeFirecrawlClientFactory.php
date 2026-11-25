<?php

// file generated with AI assistance: Claude Code - 2026-10-02 23:51:30 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\Testing;

use Dmstr\Firecrawl\Client\FirecrawlApiException;
use Dmstr\Firecrawl\Client\FirecrawlClientFactoryInterface;

/**
 * Client factory for tests: no Firecrawl instance is contacted.
 *
 * Every create() call records the config and returns a new
 * {@see FakeFirecrawlClient} bound to this factory and keyed by the config's
 * `base_url` (trailing slash trimmed). The factory holds the scripted
 * answers and the log of all requests. A test scripts the fake before
 * running the code under test and inspects the recorded requests afterwards.
 * Under `when@test` the host application aliases FirecrawlClientFactoryInterface to this
 * class (public service), so a functional test fetches it from the
 * container:
 *
 *     $fake = static::getContainer()->get(FakeFirecrawlClientFactory::class);
 *     $fake->serve('GET', '/v2/crawl/active', ['success' => true, 'crawls' => []]);
 *     $fake->queue('POST', '/v2/crawl', ['success' => true, 'id' => 'job-1', 'url' => 'https://fc.example.org/v2/crawl/job-1']);
 *     // ... code under test calls the client ...
 *     self::assertSame(1, $fake->getRequestCount(null, 'POST', '/v2/crawl'));
 *     self::assertSame(['url' => 'https://example.org'], $fake->getRequests(null, 'POST', '/v2/crawl')[0]['body']);
 *
 * Paths are the API paths the client methods use: POST /v2/scrape, /v2/map,
 * /v2/crawl, /v2/batch/scrape; GET /v2/crawl/{id}, /v2/batch/scrape/{id},
 * /v2/crawl/active. getUrl() is matched on the path plus query of the given
 * URL after host alignment, e.g. `/v2/crawl/job-1?skip=10`.
 *
 * Scripting. Every method takes an optional `$baseUrl`: null applies to
 * every connection, a base URL only to clients created for that `base_url`
 * (trailing slash ignored). URL-specific scripts win over global ones.
 *  - serve($method, $path, $response)         persistent answer
 *  - queue($method, $path, $response)         one-shot answer, FIFO per method and path
 *  - queueException($method, $path, $e)       one-shot exception, FIFO with queue()
 *  - failWith($e)                             every call throws $e (instance unreachable)
 *  - rejectCredentials()                      every call throws FirecrawlApiException
 *                                             with status 403 and body `Forbidden`
 *  - reset()                                  forgets all scripts and recordings
 * Order of evaluation per call: failWith (URL, then global), one-shot queue
 * (URL, then global), serve (URL, then global). An unscripted call throws a
 * \LogicException naming method and path, so tests never pass by accident.
 *
 * Recording (every call is recorded before it is answered):
 *  - getRequests($baseUrl, $method, $path)    list of ['baseUrl' => string, 'method' => string,
 *                                             'path' => string, 'body' => ?array]
 *  - getRequestCount($baseUrl, $method, $path)
 *  - getCreatedConfigs()                      configs passed to create(), in order
 *  - getClientCount()                         number of clients created
 *
 * State lives in this service instance: script it after the kernel serving
 * the code under test has booted (e.g. after createClient(), before the
 * first request) and do not rely on it across kernel reboots.
 */
class FakeFirecrawlClientFactory implements FirecrawlClientFactoryInterface
{
    private const string ANY_URL = '*';

    /** @var array<string, array<string, array<string, mixed>>> baseUrl => "METHOD path" => response */
    private array $served = [];

    /** @var array<string, array<string, list<array{kind: string, value: mixed}>>> baseUrl => "METHOD path" => queue */
    private array $queues = [];

    /** @var array<string, \Throwable> */
    private array $failures = [];

    /** @var list<array{baseUrl: string, method: string, path: string, body: ?array<mixed>}> */
    private array $requests = [];

    /** @var list<array<string, mixed>> */
    private array $configs = [];

    public function create(array $configJson): FakeFirecrawlClient
    {
        $this->configs[] = $configJson;
        $baseUrl = $configJson['base_url'] ?? '';

        return new FakeFirecrawlClient($this, self::normalizeUrl(\is_string($baseUrl) ? $baseUrl : ''));
    }

    /**
     * @param array<string, mixed> $response decoded JSON answer
     */
    public function serve(string $method, string $path, array $response, ?string $baseUrl = null): self
    {
        $this->served[self::urlKey($baseUrl)][self::routeKey($method, $path)] = $response;

        return $this;
    }

    /**
     * @param array<string, mixed> $response decoded JSON answer
     */
    public function queue(string $method, string $path, array $response, ?string $baseUrl = null): self
    {
        return $this->enqueue($baseUrl, $method, $path, 'response', $response);
    }

    public function queueException(string $method, string $path, \Throwable $exception, ?string $baseUrl = null): self
    {
        return $this->enqueue($baseUrl, $method, $path, 'exception', $exception);
    }

    public function failWith(\Throwable $exception, ?string $baseUrl = null): self
    {
        $this->failures[self::urlKey($baseUrl)] = $exception;

        return $this;
    }

    /**
     * Every call fails like an instance that rejects the API key (HTTP 403
     * with the plain-text body `Forbidden`).
     */
    public function rejectCredentials(?string $baseUrl = null): self
    {
        return $this->failWith(new FirecrawlApiException('Firecrawl request failed with HTTP 403: Forbidden', 403, 'Forbidden'), $baseUrl);
    }

    public function reset(): void
    {
        $this->served = [];
        $this->queues = [];
        $this->failures = [];
        $this->requests = [];
        $this->configs = [];
    }

    /**
     * @return list<array{baseUrl: string, method: string, path: string, body: ?array<mixed>}>
     */
    public function getRequests(?string $baseUrl = null, ?string $method = null, ?string $path = null): array
    {
        $baseUrl = null === $baseUrl ? null : self::normalizeUrl($baseUrl);
        $method = null === $method ? null : strtoupper($method);

        return array_values(array_filter(
            $this->requests,
            static fn (array $entry): bool => (null === $baseUrl || $entry['baseUrl'] === $baseUrl)
                && (null === $method || $entry['method'] === $method)
                && (null === $path || $entry['path'] === $path),
        ));
    }

    public function getRequestCount(?string $baseUrl = null, ?string $method = null, ?string $path = null): int
    {
        return \count($this->getRequests($baseUrl, $method, $path));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getCreatedConfigs(): array
    {
        return $this->configs;
    }

    public function getClientCount(): int
    {
        return \count($this->configs);
    }

    /**
     * Records a call and returns the scripted answer. Called by
     * FakeFirecrawlClient.
     *
     * @internal
     *
     * @param array<mixed>|null $body
     *
     * @return array<string, mixed>
     *
     * @throws \Throwable       as scripted
     * @throws \LogicException  for an unscripted call
     */
    public function handle(string $baseUrl, string $method, string $path, ?array $body): array
    {
        $method = strtoupper($method);
        $this->requests[] = ['baseUrl' => $baseUrl, 'method' => $method, 'path' => $path, 'body' => $body];

        $failure = $this->failures[$baseUrl] ?? $this->failures[self::ANY_URL] ?? null;
        if (null !== $failure) {
            throw $failure;
        }

        $route = self::routeKey($method, $path);
        foreach ([$baseUrl, self::ANY_URL] as $key) {
            if (!empty($this->queues[$key][$route])) {
                $entry = array_shift($this->queues[$key][$route]);
                if ('exception' === $entry['kind']) {
                    throw $entry['value'];
                }

                return $entry['value'];
            }
        }

        $served = $this->served[$baseUrl][$route] ?? $this->served[self::ANY_URL][$route] ?? null;
        if (null !== $served) {
            return $served;
        }

        throw new \LogicException(\sprintf('FakeFirecrawlClientFactory: no scripted answer for %s %s (base URL %s).', $method, $path, '' !== $baseUrl ? $baseUrl : '?'));
    }

    private function enqueue(?string $baseUrl, string $method, string $path, string $kind, mixed $value): self
    {
        $this->queues[self::urlKey($baseUrl)][self::routeKey($method, $path)][] = ['kind' => $kind, 'value' => $value];

        return $this;
    }

    private static function routeKey(string $method, string $path): string
    {
        return strtoupper($method).' '.$path;
    }

    private static function urlKey(?string $baseUrl): string
    {
        return null === $baseUrl ? self::ANY_URL : self::normalizeUrl($baseUrl);
    }

    private static function normalizeUrl(string $baseUrl): string
    {
        return rtrim(trim($baseUrl), '/');
    }
}
