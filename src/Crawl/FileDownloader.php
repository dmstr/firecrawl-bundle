<?php

// file generated with AI assistance: Claude Code - 2026-10-22 08:30:00 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\Crawl;

use Symfony\Component\DomCrawler\UriResolver;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Downloads a document file of the crawled site with the session cookie. The
 * cookie is sent to the host of the first URL only, also when a redirect
 * leads elsewhere, and the download stops at a size limit.
 */
final class FileDownloader
{
    private const int MAX_REDIRECTS = 5;

    public function __construct(private readonly HttpClientInterface $httpClient)
    {
    }

    /**
     * @param array<string, list<string>>|null $headers receives the headers of the final response, for a download URL without a file extension
     *
     * @throws \RuntimeException when the file cannot be fetched or is larger than $maxBytes
     */
    public function download(string $url, ?string $cookie, int $maxBytes, ?array &$headers = null): string
    {
        $host = parse_url($url, \PHP_URL_HOST);
        $current = $url;

        try {
            for ($i = 0; $i <= self::MAX_REDIRECTS; ++$i) {
                $sendCookie = null !== $cookie && parse_url($current, \PHP_URL_HOST) === $host;
                $response = $this->httpClient->request('GET', $current, [
                    'max_redirects' => 0,
                    'headers' => $sendCookie ? ['Cookie' => $cookie] : [],
                ]);
                $status = $response->getStatusCode();

                if ($status >= 300 && $status < 400 && '' !== ($response->getHeaders(false)['location'][0] ?? '')) {
                    $current = UriResolver::resolve($response->getHeaders(false)['location'][0], $current);
                    $response->cancel();
                    continue;
                }
                if ($status >= 400 || $status < 200) {
                    $response->cancel();
                    throw new \RuntimeException(\sprintf('HTTP %d', $status));
                }

                $headers = $response->getHeaders(false);
                $body = '';
                foreach ($this->httpClient->stream($response) as $chunk) {
                    $body .= $chunk->getContent();
                    if (\strlen($body) > $maxBytes) {
                        $response->cancel();
                        throw new \RuntimeException(\sprintf('larger than %d bytes', $maxBytes));
                    }
                }

                return $body;
            }
        } catch (TransportExceptionInterface $e) {
            throw new \RuntimeException($e->getMessage(), 0, $e);
        }

        throw new \RuntimeException('too many redirects');
    }
}

// - revised 2026-11-18
