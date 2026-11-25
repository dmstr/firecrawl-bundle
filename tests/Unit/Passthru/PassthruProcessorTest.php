<?php

// file generated with AI assistance: Claude Code - 2026-10-03 00:09:10 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\Tests\Unit\Passthru;

use ApiPlatform\Metadata\Post;
use Dmstr\Firecrawl\Client\FirecrawlApiException;
use Dmstr\Firecrawl\State\Passthru\BatchScrapeProcessor;
use Dmstr\Firecrawl\State\Passthru\CrawlProcessor;
use Dmstr\Firecrawl\State\Passthru\MapProcessor;
use Dmstr\Firecrawl\State\Passthru\ScrapeProcessor;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Kernel-free test of the pass-through processors over the real
 * PassthruService (see PassthruFixturesTrait): raw body and query
 * parameter in, JsonResponse out; 502 as problem document, 4xx thrown.
 */
final class PassthruProcessorTest extends TestCase
{
    use PassthruFixturesTrait;

    protected function setUp(): void
    {
        $this->setUpPassthru();
        $this->stored = [self::connection('default', self::DEFAULT_URL), self::connection('staging', self::STAGING_URL)];
    }

    public function testEachProcessorAnswersWithTheRemotePayloadVerbatim(): void
    {
        $cases = [
            [new ScrapeProcessor($this->service()), '/v2/scrape', '{"url": "https://example.org"}'],
            [new MapProcessor($this->service()), '/v2/map', '{"url": "https://example.org"}'],
            [new CrawlProcessor($this->service()), '/v2/crawl', '{"url": "https://example.org"}'],
            [new BatchScrapeProcessor($this->service()), '/v2/batch/scrape', '{"urls": ["https://example.org"]}'],
        ];
        foreach ($cases as [$processor, $path, $body]) {
            $this->fake->queue('POST', $path, ['success' => true, 'path' => $path, 'data' => ['sourceURL' => 'https://example.org/ä']]);

            $response = $processor->process(null, new Post(), [], ['request' => self::request($body, 'staging')]);

            self::assertSame(200, $response->getStatusCode());
            self::assertSame('application/json', $response->headers->get('Content-Type'));
            self::assertSame('{"success":true,"path":"'.$path.'","data":{"sourceURL":"https://example.org/ä"}}', $response->getContent());
        }
        self::assertSame(4, $this->fake->getRequestCount(self::STAGING_URL));
    }

    public function testEmptyAnswerIsAnEmptyObject(): void
    {
        $this->fake->serve('POST', '/v2/scrape', []);

        $response = (new ScrapeProcessor($this->service()))->process(null, new Post(), [], ['request' => self::request('{"url": "https://example.org"}', 'default')]);

        self::assertSame('{}', $response->getContent());
    }

    public function testRemoteFailureIsAProblemDocumentWithTheDetail(): void
    {
        $this->fake->queueException('POST', '/v2/map', new FirecrawlApiException('Firecrawl request failed with HTTP 500', 500, 'boom'));

        $response = (new MapProcessor($this->service()))->process(null, new Post(), [], ['request' => self::request('{"url": "https://example.org"}', 'default')]);

        self::assertSame(502, $response->getStatusCode());
        self::assertSame('application/problem+json', $response->headers->get('Content-Type'));
        self::assertSame(['type' => '/errors/502', 'title' => 'An error occurred', 'status' => 502, 'detail' => 'Firecrawl map failed: HTTP 500: boom'], json_decode((string) $response->getContent(), true));
    }

    public function testClientErrorsAreThrown(): void
    {
        $this->expectException(UnprocessableEntityHttpException::class);
        $this->expectExceptionMessage('Several active firecrawl connections');

        (new ScrapeProcessor($this->service()))->process(null, new Post(), [], ['request' => self::request('{"url": "https://example.org"}', '')]);
    }

    private static function request(string $body, string $connection): Request
    {
        return Request::create('/api/firecrawl/x'.('' === $connection ? '' : '?apiConfiguration='.$connection), 'POST', content: $body);
    }
}
