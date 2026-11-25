<?php

// file generated with AI assistance: Claude Code - 2026-10-02 23:51:30 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\Client;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Firecrawl v2 REST client of one connection on Symfony HttpClient.
 *
 * Options from the connection's configJson:
 *  - `base_url`: required; a trailing slash is dropped;
 *  - `api_key`: `Authorization: Bearer <key>` only when a non-empty string;
 *  - `timeout`: idle timeout and max_duration per request (default 30 s);
 *  - `verify_ssl`: verify_peer and verify_host (default true).
 *
 * Create it through {@see FirecrawlClientFactory}; the constructor performs
 * no I/O.
 */
final class FirecrawlClient implements FirecrawlClientInterface
{
    public const int DEFAULT_TIMEOUT = 30;

    private readonly string $baseUrl;

    private readonly ?string $apiKey;

    private readonly int $timeout;

    private readonly bool $verifySsl;

    /**
     * @param array<string, mixed> $config the connection's configJson
     *
     * @throws \InvalidArgumentException when `base_url` is missing or empty
     */
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        array $config,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        $baseUrl = $config['base_url'] ?? null;
        if (!\is_string($baseUrl) || '' === trim($baseUrl)) {
            throw new \InvalidArgumentException('Firecrawl connection configuration has no "base_url".');
        }

        $this->baseUrl = rtrim(trim($baseUrl), '/');
        $this->apiKey = \is_string($config['api_key'] ?? null) && '' !== $config['api_key'] ? $config['api_key'] : null;
        $this->timeout = \is_int($config['timeout'] ?? null) && $config['timeout'] > 0 ? $config['timeout'] : self::DEFAULT_TIMEOUT;
        $this->verifySsl = \is_bool($config['verify_ssl'] ?? null) ? $config['verify_ssl'] : true;
    }

    public function scrape(array $body): array
    {
        return $this->request('POST', '/v2/scrape', $body);
    }

    public function map(array $body): array
    {
        return $this->request('POST', '/v2/map', $body);
    }

    public function crawl(array $body): array
    {
        return $this->request('POST', '/v2/crawl', $body);
    }

    public function batchScrape(array $body): array
    {
        return $this->request('POST', '/v2/batch/scrape', $body);
    }

    public function getCrawl(string $id): array
    {
        return $this->request('GET', '/v2/crawl/'.rawurlencode($id));
    }

    public function getBatchScrape(string $id): array
    {
        return $this->request('GET', '/v2/batch/scrape/'.rawurlencode($id));
    }

    public function activeCrawls(): array
    {
        return $this->request('GET', '/v2/crawl/active');
    }

    public function getUrl(string $absoluteUrl): array
    {
        return $this->request('GET', self::pathAndQuery($absoluteUrl), null, true);
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    /**
     * Path and query of an absolute (or root-relative) URL, e.g.
     * `/v2/crawl/x?skip=10` for `http://other-host/v2/crawl/x?skip=10`.
     * Scheme, credentials, host, port and fragment are dropped.
     *
     * @throws \InvalidArgumentException when the URL has no path
     */
    public static function pathAndQuery(string $absoluteUrl): string
    {
        $parts = parse_url($absoluteUrl);
        $path = false !== $parts ? ($parts['path'] ?? '') : '';
        if ('' === $path || !str_starts_with($path, '/')) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a URL with a path.', $absoluteUrl));
        }

        return $path.(isset($parts['query']) && '' !== $parts['query'] ? '?'.$parts['query'] : '');
    }

    /**
     * @param array<string, mixed>|null $body
     *
     * @return array<string, mixed>
     *
     * @throws FirecrawlApiException
     */
    private function request(string $method, string $path, ?array $body = null, bool $originOnly = false): array
    {
        // getUrl(): the path of a handed-out URL is absolute on the
        // instance, so only the origin of base_url is used.
        $url = ($originOnly ? $this->origin() : $this->baseUrl).$path;

        $options = [
            'headers' => array_filter([
                'Accept' => 'application/json',
                'Authorization' => null !== $this->apiKey ? 'Bearer '.$this->apiKey : null,
            ]),
            'timeout' => $this->timeout,
            'max_duration' => $this->timeout,
            'verify_peer' => $this->verifySsl,
            'verify_host' => $this->verifySsl,
        ];
        if (null !== $body) {
            // `json` would turn an empty array into `[]`; Firecrawl expects an object.
            $options['headers']['Content-Type'] = 'application/json';
            $options['body'] = [] === $body ? '{}' : json_encode($body, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION);
        }

        try {
            $response = $this->httpClient->request($method, $url, $options);
            $status = $response->getStatusCode();
            $content = $response->getContent(false);
        } catch (TransportExceptionInterface $e) {
            $this->logger->warning('Firecrawl {method} {path} failed: {error}', ['method' => $method, 'path' => $path, 'error' => $e->getMessage()]);

            throw new FirecrawlApiException(\sprintf('Firecrawl %s %s failed: %s', $method, $path, $e->getMessage()), null, null, $e);
        }

        $this->logger->debug('Firecrawl {method} {path}: HTTP {status}', ['method' => $method, 'path' => $path, 'status' => $status]);

        if ($status >= 400) {
            $excerpt = substr($content, 0, FirecrawlApiException::MAX_BODY_BYTES);
            $this->logger->warning('Firecrawl {method} {path}: HTTP {status}', ['method' => $method, 'path' => $path, 'status' => $status]);

            throw new FirecrawlApiException(\sprintf('Firecrawl %s %s failed with HTTP %d: %s', $method, $path, $status, $excerpt), $status, $excerpt);
        }

        $trimmed = ltrim($content);
        $decoded = null;
        if (str_starts_with($trimmed, '{')) {
            try {
                $decoded = json_decode($trimmed, true, 512, \JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                throw new FirecrawlProtocolException(\sprintf('Firecrawl %s %s returned malformed JSON (HTTP %d): %s', $method, $path, $status, $e->getMessage()), $status, $content, $e);
            }
        }
        if (!\is_array($decoded)) {
            throw new FirecrawlProtocolException(\sprintf('Firecrawl %s %s returned no JSON object (HTTP %d).', $method, $path, $status), $status, $content);
        }

        return $decoded;
    }

    private function origin(): string
    {
        $parts = parse_url($this->baseUrl);
        if (false === $parts || !isset($parts['scheme'], $parts['host'])) {
            return $this->baseUrl;
        }

        return $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
    }
}
