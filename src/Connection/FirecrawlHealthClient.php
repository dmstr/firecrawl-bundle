<?php

// file generated with AI assistance: Claude Code - 2026-10-05 13:30:00 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\Connection;

use Dmstr\ApiConfiguration\ApiClient\AuthenticationFailedException;
use Dmstr\ApiConfiguration\ApiClient\RestApiClientInterface;
use Dmstr\Firecrawl\Client\FirecrawlApiException;
use Dmstr\Firecrawl\Client\FirecrawlClientFactoryInterface;
use Dmstr\Firecrawl\Client\FirecrawlProtocolException;

/**
 * Thin ApiClientInterface adapter for `firecrawl` connections: it exists for
 * the platform's health check only, the domain methods (projects, todos,
 * ...) are no-ops.
 *
 * getHealthInfo() calls `GET /v2/crawl/active` through a fresh client from
 * {@see FirecrawlClientFactoryInterface} and never throws:
 *  - success: `status: ok`, `authenticated: true`, `message`,
 *    `metadata.custom.activeCrawls` (int);
 *  - failure: `status: error`, `authenticated: false`, `message` and
 *    `error.code` UNAUTHENTICATED (HTTP 401/403, whatever the body format),
 *    PROTOCOL (2xx without a usable JSON object or without a `crawls` list)
 *    or UNREACHABLE (everything else: network, TLS, timeout, HTTP 5xx and
 *    other statuses, unusable configuration).
 *
 * Construction performs no I/O; the platform's health provider classifies
 * any exception thrown while creating the client as HEALTH_CHECK_ERROR, so
 * the probe belongs in getHealthInfo().
 */
final class FirecrawlHealthClient implements RestApiClientInterface
{
    public const string CODE_UNREACHABLE = 'UNREACHABLE';
    public const string CODE_UNAUTHENTICATED = 'UNAUTHENTICATED';
    public const string CODE_PROTOCOL = 'PROTOCOL';

    private readonly string $baseUrl;

    /**
     * @param array<string, mixed> $config the connection's configJson
     *
     * @throws \InvalidArgumentException when the configuration has no base_url
     */
    public function __construct(
        private readonly array $config,
        private readonly FirecrawlClientFactoryInterface $clientFactory,
    ) {
        $baseUrl = $config['base_url'] ?? null;
        if (!\is_string($baseUrl) || '' === $baseUrl) {
            throw new \InvalidArgumentException('Firecrawl connection configuration has no "base_url".');
        }
        $this->baseUrl = $baseUrl;
    }

    public function authenticate(): void
    {
        $health = $this->probe();
        if ('ok' !== $health['status']) {
            throw new AuthenticationFailedException($health['message'] ?? 'Firecrawl probe failed.');
        }
    }

    public function getType(): string
    {
        return 'rest';
    }

    public function getApiName(): string
    {
        return FirecrawlExtension::NAME;
    }

    /**
     * @return array<string, mixed> raw health data for the platform's HealthNormalizer
     */
    public function getHealthInfo(): array
    {
        return $this->probe();
    }

    public function getEndpoint(): string
    {
        return $this->baseUrl;
    }

    public function getProjects(): array
    {
        return [];
    }

    public function getTodos(string $projectId): array
    {
        return [];
    }

    public function getTodo(string $projectId, string $todoId): ?array
    {
        return null;
    }

    /**
     * Maps a probe failure to UNAUTHENTICATED, PROTOCOL or UNREACHABLE. The
     * exception and its previous chain are searched for a
     * FirecrawlApiException with status 401 or 403.
     */
    public static function classify(\Throwable $e): string
    {
        for ($current = $e; null !== $current; $current = $current->getPrevious()) {
            if ($current instanceof FirecrawlApiException && \in_array($current->getStatusCode(), [401, 403], true)) {
                return self::CODE_UNAUTHENTICATED;
            }
        }

        if ($e instanceof FirecrawlProtocolException || $e instanceof \JsonException) {
            return self::CODE_PROTOCOL;
        }

        return self::CODE_UNREACHABLE;
    }

    /**
     * @return array<string, mixed>
     */
    private function probe(): array
    {
        try {
            $response = $this->clientFactory->create($this->config)->activeCrawls();
            $crawls = $response['crawls'] ?? null;
            if (!\is_array($crawls) || !array_is_list($crawls)) {
                throw new FirecrawlProtocolException('GET /v2/crawl/active returned no "crawls" list.', 200, json_encode($response) ?: null);
            }
        } catch (\Throwable $e) {
            return $this->failure($e);
        }

        return [
            'status' => 'ok',
            'authenticated' => true,
            'message' => \sprintf('Firecrawl reachable, %d active crawl(s).', \count($crawls)),
            'metadata' => [
                'apiName' => FirecrawlExtension::NAME,
                'custom' => [
                    'activeCrawls' => \count($crawls),
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function failure(\Throwable $e): array
    {
        $code = self::classify($e);
        $prefix = match ($code) {
            self::CODE_UNAUTHENTICATED => 'Firecrawl rejected the credentials',
            self::CODE_PROTOCOL => 'Firecrawl protocol error',
            default => 'Firecrawl unreachable',
        };
        $message = '' !== $e->getMessage() ? $e->getMessage() : $e::class;

        return [
            'status' => 'error',
            'authenticated' => false,
            'message' => $prefix.': '.$message,
            'error' => [
                'code' => $code,
                'message' => $message,
                'details' => ['exception' => $e::class],
            ],
        ];
    }
}
