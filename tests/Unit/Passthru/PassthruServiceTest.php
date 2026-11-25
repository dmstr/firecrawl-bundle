<?php

// file generated with AI assistance: Claude Code - 2026-10-03 00:09:10 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\Tests\Unit\Passthru;

use Dmstr\Firecrawl\Client\FirecrawlApiException;
use Dmstr\Firecrawl\Client\FirecrawlProtocolException;
use Dmstr\Firecrawl\Passthru\Operation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Security\Core\User\InMemoryUser;

/**
 * Kernel-free test of the pass-through service: the real input schemas of
 * the bundle (config/schemas), a connection resolver over stubbed Doctrine
 * collaborators, the fake client factory and an in-memory logger (see
 * PassthruFixturesTrait).
 */
final class PassthruServiceTest extends TestCase
{

    use PassthruFixturesTrait;

    protected function setUp(): void
    {
        $this->setUpPassthru();
        $this->stored = [self::connection('default', self::DEFAULT_URL)];
    }

    /**
     * @return iterable<string, array{Operation, string, array<string, mixed>}>
     */
    public static function operations(): iterable
    {
        yield 'scrape' => [Operation::Scrape, '/v2/scrape', ['url' => 'https://example.org', 'formats' => ['markdown'], 'onlyMainContent' => true]];
        yield 'map' => [Operation::Map, '/v2/map', ['url' => 'https://example.org', 'search' => 'docs', 'limit' => 5]];
        yield 'crawl' => [Operation::Crawl, '/v2/crawl', ['url' => 'https://example.org', 'limit' => 10, 'scrapeOptions' => ['formats' => ['markdown']]]];
        yield 'batch scrape' => [Operation::BatchScrape, '/v2/batch/scrape', ['urls' => ['https://example.org/a', 'https://example.org/b'], 'ignoreInvalidURLs' => true]];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[DataProvider('operations')]
    public function testForwardsTheBodyWithoutTheConnectionToTheRightPath(Operation $operation, string $path, array $body): void
    {
        $this->stored[] = self::connection('staging', self::STAGING_URL);
        $answer = ['success' => true, 'data' => ['markdown' => '# Hi'], 'id' => 'job-1'];
        $this->fake->queue('POST', $path, $answer);

        $payload = $this->service()->forward($operation->value, self::json($body + ['apiConfiguration' => 'staging']));

        self::assertSame($answer, $payload);
        $requests = $this->fake->getRequests();
        self::assertCount(1, $requests);
        self::assertSame('POST', $requests[0]['method']);
        self::assertSame($path, $requests[0]['path']);
        self::assertSame($operation->remotePath(), $requests[0]['path']);
        self::assertSame(self::STAGING_URL, $requests[0]['baseUrl']);
        self::assertSame($body, $requests[0]['body']);
    }

    public function testQueryReferenceWinsOverBodyReference(): void
    {
        $this->stored[] = self::connection('staging', self::STAGING_URL);
        $this->fake->serve('POST', '/v2/scrape', ['success' => true]);

        $this->service()->forward('firecrawl_scrape', '{"url": "https://example.org", "apiConfiguration": "default"}', 'staging');

        self::assertSame(1, $this->fake->getRequestCount(self::STAGING_URL));
        self::assertSame(0, $this->fake->getRequestCount(self::DEFAULT_URL));
        self::assertSame(['url' => 'https://example.org'], $this->fake->getRequests()[0]['body']);
    }

    public function testSingleActiveConnectionIsUsedImplicitly(): void
    {
        $this->stored[] = self::connection('old', self::STAGING_URL, active: false);
        $this->fake->serve('POST', '/v2/map', ['success' => true, 'links' => []]);

        self::assertSame(['success' => true, 'links' => []], $this->service()->forward('firecrawl_map', '{"url": "https://example.org"}'));
        self::assertSame([self::DEFAULT_URL], array_column($this->fake->getCreatedConfigs(), 'base_url'));
    }

    public function testSeveralActiveConnectionsWithoutReferenceAreRejected(): void
    {
        $this->stored[] = self::connection('staging', self::STAGING_URL);

        $this->expectException(UnprocessableEntityHttpException::class);
        $this->expectExceptionMessage('choose one of: default, staging');

        $this->service()->forward('firecrawl_scrape', '{"url": "https://example.org"}');
    }

    public function testUnknownOptionsArePassedThroughAndEmptyObjectsStayObjects(): void
    {
        $this->fake->serve('POST', '/v2/scrape', ['success' => true]);

        $this->service()->forward('firecrawl_scrape', '{"url": "https://example.org", "location": {"country": "DE"}, "headers": {}, "actions": [{"type": "wait", "milliseconds": 500}], "mobile": true}');

        $body = $this->fake->getRequests()[0]['body'];
        self::assertIsArray($body);
        self::assertSame(['country' => 'DE'], $body['location']);
        self::assertSame([['type' => 'wait', 'milliseconds' => 500]], $body['actions']);
        self::assertTrue($body['mobile']);
        // `{}` must reach Firecrawl as an object, not as `[]`.
        self::assertSame('{"url":"https://example.org","location":{"country":"DE"},"headers":{},"actions":[{"type":"wait","milliseconds":500}],"mobile":true}', json_encode($body, \JSON_UNESCAPED_SLASHES));
    }

    public function testMalformedJsonIsABadRequest(): void
    {
        $this->expectException(BadRequestHttpException::class);

        $this->service()->forward('firecrawl_scrape', '{"url": ');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function schemaViolations(): iterable
    {
        yield 'scrape without url' => ['firecrawl_scrape', '{"formats": ["markdown"]}'];
        yield 'empty body' => ['firecrawl_map', ''];
        yield 'non-http url' => ['firecrawl_scrape', '{"url": "ftp://example.org/file"}'];
        yield 'relative url' => ['firecrawl_crawl', '{"url": "/blog"}'];
        yield 'empty urls' => ['firecrawl_batch_scrape', '{"urls": []}'];
        yield 'non-http item in urls' => ['firecrawl_batch_scrape', '{"urls": ["https://example.org", "file:///etc/passwd"]}'];
        yield 'wrong option type' => ['firecrawl_crawl', '{"url": "https://example.org", "limit": "ten"}'];
        yield 'empty apiConfiguration' => ['firecrawl_scrape', '{"url": "https://example.org", "apiConfiguration": ""}'];
        yield 'not an object' => ['firecrawl_scrape', '["https://example.org"]'];
    }

    #[DataProvider('schemaViolations')]
    public function testSchemaViolationIsUnprocessableAndNothingIsSent(string $operation, string $body): void
    {
        try {
            $this->service()->forward($operation, $body);
            self::fail('Expected a 422.');
        } catch (UnprocessableEntityHttpException $e) {
            self::assertStringContainsString('Input validation failed', $e->getMessage());
        }
        self::assertSame(0, $this->fake->getClientCount());
    }

    public function testRemoteHttpFailureIsABadGatewayWithStatusAndExcerpt(): void
    {
        $this->fake->queueException('POST', '/v2/crawl', new FirecrawlApiException('Firecrawl request failed with HTTP 500', 500, "{\"success\": false,\n \"error\": \"boom\"}"));

        try {
            $this->service()->forward('firecrawl_crawl', '{"url": "https://example.org"}');
            self::fail('Expected a 502.');
        } catch (HttpException $e) {
            self::assertSame(502, $e->getStatusCode());
            self::assertSame('Firecrawl crawl failed: HTTP 500: {"success": false, "error": "boom"}', $e->getMessage());
            self::assertInstanceOf(FirecrawlApiException::class, $e->getPrevious());
        }
    }

    public function testTransportFailureIsABadGatewayWithTheMessage(): void
    {
        $this->fake->failWith(new FirecrawlApiException('Connection refused for "https://fc-default.example.org/v2/map".'));

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Firecrawl map failed: Connection refused');

        $this->service()->forward('firecrawl_map', '{"url": "https://example.org"}');
    }

    public function testProtocolFailureIsABadGateway(): void
    {
        $this->fake->queueException('POST', '/v2/batch/scrape', new FirecrawlProtocolException('not JSON', 200, '<html>proxy</html>'));

        try {
            $this->service()->forward('firecrawl_batch_scrape', '{"urls": ["https://example.org"]}');
            self::fail('Expected a 502.');
        } catch (HttpException $e) {
            self::assertSame(502, $e->getStatusCode());
            self::assertSame('Firecrawl batch_scrape failed: non-JSON answer from the instance (HTTP 200).', $e->getMessage());
        }
    }

    public function testLogLineCarriesOperationConnectionUrlsAndUser(): void
    {
        $this->fake->serve('POST', '/v2/batch/scrape', ['success' => true, 'id' => 'b-1']);
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(new InMemoryUser('alice', null));

        $this->service($security)->forward('firecrawl_batch_scrape', '{"urls": ["https://example.org/a", "https://example.org/b"]}');

        self::assertCount(1, $this->logger->records);
        $record = $this->logger->records[0];
        self::assertSame('info', $record['level']);
        self::assertSame([
            'operation' => 'firecrawl_batch_scrape',
            'connection_id' => (string) $this->stored[0]->getId(),
            'connection_name' => 'default',
            'urls' => ['https://example.org/a', 'https://example.org/b'],
            'user' => 'alice',
            'outcome' => 'ok',
        ], $record['context']);
    }

    public function testLogLineWithoutUserSaysCliAndRecordsFailures(): void
    {
        $this->fake->rejectCredentials();

        try {
            $this->service()->forward('firecrawl_scrape', '{"url": "https://example.org"}');
            self::fail('Expected a 502.');
        } catch (HttpException $e) {
            self::assertSame('Firecrawl scrape failed: HTTP 403: Forbidden', $e->getMessage());
        }

        self::assertCount(1, $this->logger->records);
        self::assertSame('cli', $this->logger->records[0]['context']['user']);
        self::assertSame('error', $this->logger->records[0]['context']['outcome']);
        self::assertSame(403, $this->logger->records[0]['context']['status']);
        self::assertSame(['https://example.org'], $this->logger->records[0]['context']['urls']);
    }

    public function testApiKeyOfTheConnectionReachesTheFactory(): void
    {
        $this->stored = [self::connection('default', self::DEFAULT_URL, apiKey: 'fc-secret')];
        $this->fake->serve('POST', '/v2/scrape', ['success' => true]);

        $this->service()->forward('firecrawl_scrape', '{"url": "https://example.org"}');

        self::assertSame('fc-secret', $this->fake->getCreatedConfigs()[0]['api_key'] ?? null);
    }

    public function testUnknownOperationIsALogicError(): void
    {
        $this->expectException(\LogicException::class);

        $this->service()->forward('firecrawl_job_sync', '{}');
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function json(array $data): string
    {
        return json_encode($data, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
    }
}
