<?php

// file generated with AI assistance: Claude Code - 2026-11-05 12:00:00 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\Connection;

use Dmstr\ApiConfiguration\ApiClient\AuthenticationFailedException;
use Dmstr\ApiConfiguration\ApiClient\RestApiClientInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Health check of a `file-converter` connection: `GET <base_url>/health` must
 * answer 200 with `{"status": "ok"}`. Never throws from getHealthInfo(); the
 * domain methods are no-ops, as in {@see FirecrawlHealthClient}.
 */
final class FileConverterHealthClient implements RestApiClientInterface
{
    private const int TIMEOUT = 10;

    private readonly string $baseUrl;

    /**
     * @param array<string, mixed> $config the connection's configJson
     *
     * @throws \InvalidArgumentException when the configuration has no base_url
     */
    public function __construct(array $config, private readonly HttpClientInterface $httpClient)
    {
        $baseUrl = $config['base_url'] ?? null;
        if (!\is_string($baseUrl) || '' === $baseUrl) {
            throw new \InvalidArgumentException('File converter connection configuration has no "base_url".');
        }
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    public function authenticate(): void
    {
        $health = $this->getHealthInfo();
        if ('ok' !== $health['status']) {
            throw new AuthenticationFailedException($health['message']);
        }
    }

    public function getType(): string
    {
        return 'rest';
    }

    public function getApiName(): string
    {
        return FileConverterExtension::NAME;
    }

    /**
     * @return array<string, mixed>
     */
    public function getHealthInfo(): array
    {
        try {
            $response = $this->httpClient->request('GET', $this->baseUrl.'/health', ['timeout' => self::TIMEOUT]);
            $status = $response->getStatusCode();
            $ok = 200 === $status && 'ok' === ($response->toArray(false)['status'] ?? null);
        } catch (\Throwable $e) {
            return self::failure('Converter unreachable: '.$e->getMessage());
        }

        return $ok
            ? ['status' => 'ok', 'authenticated' => true, 'message' => 'Converter reachable.', 'metadata' => ['apiName' => FileConverterExtension::NAME]]
            : self::failure(\sprintf('Converter answered HTTP %d without status "ok".', $status));
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
     * @return array<string, mixed>
     */
    private static function failure(string $message): array
    {
        return ['status' => 'error', 'authenticated' => false, 'message' => $message, 'error' => ['code' => 'UNREACHABLE', 'message' => $message]];
    }
}
