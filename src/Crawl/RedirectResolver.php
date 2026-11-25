<?php

// file generated with AI assistance: Claude Code - 2026-10-19 14:32:00 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\Crawl;

use Symfony\Component\DomCrawler\UriResolver;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Follows the redirects of a URL with the site's session cookie and returns
 * the final URL. Firecrawl drops a `Cookie` header when it follows a redirect
 * and then lands on the login page, so the crawl scrapes the final URL
 * directly. The cookie is only sent to the host of the URL it was meant for.
 */
final class RedirectResolver
{
    private const int MAX_REDIRECTS = 8;

    public function __construct(private readonly HttpClientInterface $httpClient)
    {
    }

    /**
     * The URL itself when it does not redirect or cannot be reached (the scrape then reports the problem).
     */
    public function resolve(string $url, ?string $cookie): string
    {
        $host = parse_url($url, \PHP_URL_HOST);
        $current = $url;

        try {
            for ($i = 0; $i < self::MAX_REDIRECTS; ++$i) {
                $sendCookie = null !== $cookie && parse_url($current, \PHP_URL_HOST) === $host;
                $response = $this->httpClient->request('GET', $current, [
                    'max_redirects' => 0,
                    'headers' => $sendCookie ? ['Cookie' => $cookie] : [],
                ]);
                $status = $response->getStatusCode();
                $location = $response->getHeaders(false)['location'][0] ?? '';
                $response->cancel();

                if ($status < 300 || $status >= 400 || '' === $location) {
                    break;
                }
                $current = UriResolver::resolve($location, $current);
            }
        } catch (TransportExceptionInterface) {
            return $url;
        }

        return UrlClassifier::withoutFragment($current);
    }
}
