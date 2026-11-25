<?php

// file generated with AI assistance: Claude Code - 2026-10-02 23:51:30 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\Tests\Unit\Connection;

use Dmstr\Firecrawl\Client\FirecrawlApiException;
use Dmstr\Firecrawl\Client\FirecrawlClient;
use Dmstr\Firecrawl\Client\FirecrawlClientFactory;
use Dmstr\Firecrawl\Client\FirecrawlProtocolException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * Kernel-free checks of the Firecrawl v2 REST client against Symfony's
 * MockHttpClient: request shape (URL, headers, body, options) and the
 * mapping of error responses to the bundle's exceptions.
 */
final class FirecrawlClientTest extends TestCase
{
    private const string BASE_URL = 'https://firecrawl.example.org';

    /** @var list<array{method: string, url: string, options: array<string, mixed>}> */
    private array $requests = [];

    public function testBearerHeaderIsSentOnlyWithAKey(): void
    {
        $this->client([], ['api_key' => 'fc-secret'])->activeCrawls();
        $this->client([], ['api_key' => ''])->activeCrawls();
        $this->client([])->activeCrawls();

        self::assertContains('Authorization: Bearer fc-secret', $this->requests[0]['options']['headers']);
        foreach ([1, 2] as $i) {
            foreach ($this->requests[$i]['options']['headers'] as $header) {
                self::assertStringStartsNotWith('Authorization:', $header);
            }
        }
        self::assertContains('Accept: application/json', $this->requests[2]['options']['headers']);
    }

    public function testTimeoutAndTlsOptions(): void
    {
        $this->client([])->activeCrawls();
        $this->client([], ['timeout' => 12, 'verify_ssl' => false])->activeCrawls();

        self::assertEquals(30, $this->requests[0]['options']['timeout']);
        self::assertEquals(30, $this->requests[0]['options']['max_duration']);
        self::assertTrue($this->requests[0]['options']['verify_peer']);
        self::assertTrue($this->requests[0]['options']['verify_host']);

        self::assertEquals(12, $this->requests[1]['options']['timeout']);
        self::assertEquals(12, $this->requests[1]['options']['max_duration']);
        self::assertFalse($this->requests[1]['options']['verify_peer']);
        self::assertFalse($this->requests[1]['options']['verify_host']);
    }

    public function testJsonBodyIsForwardedVerbatim(): void
    {
        $body = ['url' => 'https://example.org/a b', 'formats' => ['markdown'], 'onlyMainContent' => true, 'waitFor' => 0, 'location' => ['country' => 'DE']];

        $result = $this->client([new MockResponse('{"success": true, "data": {"markdown": "# Hi"}}')])->scrape($body);

        self::assertSame(['success' => true, 'data' => ['markdown' => '# Hi']], $result);
        self::assertSame('POST', $this->requests[0]['method']);
        self::assertSame(self::BASE_URL.'/v2/scrape', $this->requests[0]['url']);
        self::assertSame($body, json_decode($this->requests[0]['options']['body'], true));
        self::assertContains('Content-Type: application/json', $this->requests[0]['options']['headers']);
    }

    public function testEmptyBodyIsSentAsAnObject(): void
    {
        $this->client([])->map([]);

        self::assertSame('{}', $this->requests[0]['options']['body']);
    }

    public function testEndpointsAndTrailingSlash(): void
    {
        $client = $this->client(array_fill(0, 7, new MockResponse('{"success": true}')), ['base_url' => self::BASE_URL.'/']);

        $client->scrape(['url' => 'https://example.org']);
        $client->map(['url' => 'https://example.org']);
        $client->crawl(['url' => 'https://example.org']);
        $client->batchScrape(['urls' => ['https://example.org']]);
        $client->getCrawl('job/1');
        $client->getBatchScrape('batch-1');
        $client->activeCrawls();

        self::assertSame(self::BASE_URL, $client->getBaseUrl());
        self::assertSame([
            'POST '.self::BASE_URL.'/v2/scrape',
            'POST '.self::BASE_URL.'/v2/map',
            'POST '.self::BASE_URL.'/v2/crawl',
            'POST '.self::BASE_URL.'/v2/batch/scrape',
            'GET '.self::BASE_URL.'/v2/crawl/job%2F1',
            'GET '.self::BASE_URL.'/v2/batch/scrape/batch-1',
            'GET '.self::BASE_URL.'/v2/crawl/active',
        ], array_map(static fn (array $r): string => $r['method'].' '.$r['url'], $this->requests));
        self::assertArrayNotHasKey('body', array_filter($this->requests[4]['options'], static fn ($v) => null !== $v && '' !== $v));
    }

    public function testPlainTextForbiddenBecomesAnApiException(): void
    {
        try {
            $this->client([new MockResponse('Forbidden', ['http_code' => 403, 'response_headers' => ['content-type' => 'text/plain']])])->activeCrawls();
            self::fail('No exception thrown.');
        } catch (FirecrawlApiException $e) {
            self::assertNotInstanceOf(FirecrawlProtocolException::class, $e);
            self::assertSame(403, $e->getStatusCode());
            self::assertSame('Forbidden', $e->getResponseBody());
            self::assertStringContainsString('HTTP 403', $e->getMessage());
            self::assertNull($e->getPrevious());
        }
    }

    public function testErrorBodyIsTruncatedTo500Bytes(): void
    {
        try {
            $this->client([new MockResponse(str_repeat('x', 2000), ['http_code' => 500])])->crawl(['url' => 'https://example.org']);
            self::fail('No exception thrown.');
        } catch (FirecrawlApiException $e) {
            self::assertSame(500, $e->getStatusCode());
            self::assertSame(500, \strlen((string) $e->getResponseBody()));
        }
    }

    public function testHtmlWithStatus200IsAProtocolError(): void
    {
        try {
            $this->client([new MockResponse('<html><body>Proxy login</body></html>', ['http_code' => 200])])->activeCrawls();
            self::fail('No exception thrown.');
        } catch (FirecrawlProtocolException $e) {
            self::assertSame(200, $e->getStatusCode());
            self::assertStringContainsString('Proxy login', (string) $e->getResponseBody());
        }
    }

    public function testJsonListAndMalformedJsonAreProtocolErrors(): void
    {
        $client = $this->client([new MockResponse('[1, 2]'), new MockResponse('{"success": tru')]);

        foreach ([1, 2] as $_) {
            try {
                $client->activeCrawls();
                self::fail('No exception thrown.');
            } catch (FirecrawlProtocolException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testTransportErrorIsWrapped(): void
    {
        try {
            $this->client([new MockResponse('', ['error' => 'Could not resolve host: firecrawl.example.org'])])->activeCrawls();
            self::fail('No exception thrown.');
        } catch (FirecrawlApiException $e) {
            self::assertNull($e->getStatusCode());
            self::assertNull($e->getResponseBody());
            self::assertInstanceOf(TransportExceptionInterface::class, $e->getPrevious());
            self::assertStringContainsString('Could not resolve host', $e->getMessage());
        }
    }

    public function testTransportExceptionFromTheCallbackIsWrapped(): void
    {
        $client = new FirecrawlClient(new MockHttpClient(static fn () => throw new TransportException('Connection refused')), ['base_url' => self::BASE_URL]);

        $this->expectException(FirecrawlApiException::class);
        $this->expectExceptionMessage('Connection refused');

        $client->activeCrawls();
    }

    public function testGetUrlAlignsSchemeAndHostAndKeepsPathAndQuery(): void
    {
        $this->client([], ['base_url' => 'https://firecrawl.example.org:8443/prefix/'])->getUrl('http://other-host/v2/crawl/x?skip=10');

        self::assertSame('GET', $this->requests[0]['method']);
        self::assertSame('https://firecrawl.example.org:8443/v2/crawl/x?skip=10', $this->requests[0]['url']);
    }

    public function testGetUrlWithoutPathIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->client([])->getUrl('http://other-host');
    }

    public function testPathAndQuery(): void
    {
        self::assertSame('/v2/crawl/x?skip=10', FirecrawlClient::pathAndQuery('http://user:pw@other-host:99/v2/crawl/x?skip=10#frag'));
        self::assertSame('/v2/crawl/x', FirecrawlClient::pathAndQuery('https://other-host/v2/crawl/x'));
    }

    public function testFactoryRejectsMissingBaseUrl(): void
    {
        $factory = new FirecrawlClientFactory(new MockHttpClient());

        foreach ([[], ['base_url' => 42], ['base_url' => ' ']] as $config) {
            try {
                $factory->create($config);
                self::fail('No exception for '.json_encode($config));
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        self::assertInstanceOf(FirecrawlClient::class, $factory->create(['base_url' => self::BASE_URL, '_apiConfigurationId' => 'x']));
    }

    /**
     * @param list<MockResponse>   $responses answered in order; when empty, every request gets `{"success": true, "crawls": []}`
     * @param array<string, mixed> $config
     */
    private function client(array $responses, array $config = []): FirecrawlClient
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$responses): MockResponse {
            $this->requests[] = ['method' => $method, 'url' => $url, 'options' => $options];

            return [] === $responses ? new MockResponse('{"success": true, "crawls": []}') : array_shift($responses);
        });

        return (new FirecrawlClientFactory($http))->create($config + ['base_url' => self::BASE_URL]);
    }
}
