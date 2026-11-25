<?php

// file generated with AI assistance: Claude Code - 2026-10-03 00:09:10 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\Tests\Integration\Passthru;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use Dmstr\Firecrawl\Client\FirecrawlApiException;
use Dmstr\Firecrawl\Passthru\Operation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Functional test of the pass-through resources under /api/firecrawl
 * (scrape, map, crawl, batch_scrape) against the fake client factory; the
 * test firewall authenticates as admin (ROLE_ADMIN holds
 * `firecrawl:passthru:execute`). Needs the PostgreSQL database from
 * `DATABASE_URL`. Role checks (401/403) are
 * {@see \Dmstr\Firecrawl\Tests\Integration\Access\FirecrawlAccessTest}.
 */
#[Group('database')]
final class FirecrawlPassthruResourceTest extends ApiTestCase
{
    use PassthruDatabaseTrait;

    protected static ?bool $alwaysBootKernel = true;

    private const string DEFAULT_URL = 'https://fc-pt-default.example.org';
    private const string STAGING_URL = 'https://fc-pt-staging.example.org';

    /**
     * @return iterable<string, array{Operation, array<string, mixed>, array<string, mixed>}>
     */
    public static function operations(): iterable
    {
        yield 'scrape' => [Operation::Scrape, ['url' => 'https://example.org', 'formats' => ['markdown'], 'mobile' => true], ['success' => true, 'data' => ['markdown' => '# Example', 'metadata' => ['statusCode' => 200]]]];
        yield 'map' => [Operation::Map, ['url' => 'https://example.org', 'limit' => 2], ['success' => true, 'links' => [['url' => 'https://example.org/a'], ['url' => 'https://example.org/b']]]];
        yield 'crawl' => [Operation::Crawl, ['url' => 'https://example.org', 'limit' => 5, 'scrapeOptions' => ['formats' => ['markdown']]], ['success' => true, 'id' => 'crawl-1', 'url' => 'https://fc-pt-default.example.org/v2/crawl/crawl-1']];
        yield 'batch scrape' => [Operation::BatchScrape, ['urls' => ['https://example.org/a', 'https://example.org/b']], ['success' => true, 'id' => 'batch-1', 'url' => 'https://fc-pt-default.example.org/v2/batch/scrape/batch-1', 'invalidURLs' => []]];
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, mixed> $answer
     */
    #[DataProvider('operations')]
    public function testOperationReturnsTheRemotePayloadVerbatim(Operation $operation, array $body, array $answer): void
    {
        self::assertSame(0, self::foreignActiveConnections(), 'The test database holds active firecrawl connections of its own.');
        $client = $this->client();
        $this->connection('default', self::DEFAULT_URL, apiKey: 'fc-secret');
        $jobs = self::rowCount('dmstr_job');
        $refs = self::rowCount('ref_firecrawl_job');
        $fake = $this->fake();
        $fake->queue('POST', $operation->remotePath(), $answer);

        $this->post($client, '/api'.$operation->uriTemplate(), self::json($body));

        self::assertResponseStatusCodeSame(200);
        self::assertSame($answer, $client->getResponse()->toArray());
        $requests = $fake->getRequests();
        self::assertCount(1, $requests);
        self::assertSame('POST', $requests[0]['method']);
        self::assertSame($operation->remotePath(), $requests[0]['path']);
        self::assertSame(self::DEFAULT_URL, $requests[0]['baseUrl']);
        self::assertSame($body, $requests[0]['body']);
        self::assertSame('fc-secret', $fake->getCreatedConfigs()[0]['api_key'] ?? null);
        // Nothing is persisted.
        self::assertSame($jobs, self::rowCount('dmstr_job'));
        self::assertSame($refs, self::rowCount('ref_firecrawl_job'));
    }

    public function testConnectionWithoutApiKeyCreatesAConfigWithoutKey(): void
    {
        self::assertSame(0, self::foreignActiveConnections());
        $client = $this->client();
        $this->connection('default', self::DEFAULT_URL);
        $fake = $this->fake();
        $fake->serve('POST', '/v2/scrape', ['success' => true]);

        $this->post($client, '/api/firecrawl/scrape', '{"url": "https://example.org"}');

        self::assertResponseStatusCodeSame(200);
        self::assertArrayNotHasKey('api_key', $fake->getCreatedConfigs()[0]);
    }

    public function testExplicitConnectionByNameInTheBody(): void
    {
        $client = $this->client();
        $this->connection('default', self::DEFAULT_URL);
        $staging = $this->connection('staging', self::STAGING_URL);
        $fake = $this->fake();
        $fake->serve('POST', '/v2/map', ['success' => true, 'links' => []]);

        $this->post($client, '/api/firecrawl/map', self::json(['url' => 'https://example.org', 'apiConfiguration' => $staging->getName()]));

        self::assertResponseStatusCodeSame(200);
        self::assertSame(1, $fake->getRequestCount(self::STAGING_URL));
        self::assertSame(['url' => 'https://example.org'], $fake->getRequests()[0]['body']);
    }

    public function testExplicitConnectionByUuidAsQueryParameterWinsOverTheBody(): void
    {
        $client = $this->client();
        $default = $this->connection('default', self::DEFAULT_URL);
        $staging = $this->connection('staging', self::STAGING_URL);
        $fake = $this->fake();
        $fake->serve('POST', '/v2/scrape', ['success' => true]);

        $this->post($client, '/api/firecrawl/scrape?apiConfiguration='.$staging->getId(), self::json(['url' => 'https://example.org', 'apiConfiguration' => $default->getName()]));

        self::assertResponseStatusCodeSame(200);
        self::assertSame(1, $fake->getRequestCount(self::STAGING_URL));
        self::assertSame(0, $fake->getRequestCount(self::DEFAULT_URL));
        self::assertSame(['url' => 'https://example.org'], $fake->getRequests()[0]['body']);
    }

    public function testNoActiveConnectionIsUnprocessable(): void
    {
        self::assertSame(0, self::foreignActiveConnections());
        $client = $this->client();
        $this->connection('old', self::DEFAULT_URL, active: false);

        $this->post($client, '/api/firecrawl/scrape', '{"url": "https://example.org"}');

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('No active firecrawl connection', (string) $client->getResponse()->toArray(false)['detail']);
        self::assertSame(0, $this->fake()->getClientCount());
    }

    public function testTwoActiveConnectionsAreUnprocessableAndListed(): void
    {
        self::assertSame(0, self::foreignActiveConnections());
        $client = $this->client();
        $this->connection('default', self::DEFAULT_URL);
        $this->connection('staging', self::STAGING_URL);

        $this->post($client, '/api/firecrawl/crawl', '{"url": "https://example.org"}');

        self::assertResponseStatusCodeSame(422);
        $detail = (string) $client->getResponse()->toArray(false)['detail'];
        self::assertStringContainsString('Several active firecrawl connections', $detail);
        self::assertStringContainsString(self::PREFIX.'default', $detail);
        self::assertStringContainsString(self::PREFIX.'staging', $detail);
    }

    public function testInactiveExplicitConnectionIsUnprocessable(): void
    {
        $client = $this->client();
        $this->connection('default', self::DEFAULT_URL);
        $old = $this->connection('old', self::STAGING_URL, active: false);

        $this->post($client, '/api/firecrawl/scrape', self::json(['url' => 'https://example.org', 'apiConfiguration' => $old->getName()]));

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('is inactive', (string) $client->getResponse()->toArray(false)['detail']);
        self::assertSame(0, $this->fake()->getClientCount());
    }

    public function testMalformedJsonIsABadRequest(): void
    {
        $client = $this->client();
        $this->connection('default', self::DEFAULT_URL);

        $this->post($client, '/api/firecrawl/scrape', '{"url": ');

        self::assertResponseStatusCodeSame(400);
        self::assertSame(0, $this->fake()->getClientCount());
    }

    public function testMissingUrlIsUnprocessable(): void
    {
        $client = $this->client();
        $this->connection('default', self::DEFAULT_URL);

        $this->post($client, '/api/firecrawl/map', '{"limit": 3}');

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Input validation failed', (string) $client->getResponse()->toArray(false)['detail']);
        self::assertSame(0, $this->fake()->getClientCount());
    }

    public function testRemoteFailureIsABadGatewayWithTheRemoteStatus(): void
    {
        $client = $this->client();
        $this->connection('default', self::DEFAULT_URL);
        $this->fake()->queueException('POST', '/v2/scrape', new FirecrawlApiException('Firecrawl request failed with HTTP 500', 500, 'boom'));

        $this->post($client, '/api/firecrawl/scrape', '{"url": "https://example.org"}');

        self::assertResponseStatusCodeSame(502);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
        self::assertSame('Firecrawl scrape failed: HTTP 500: boom', $client->getResponse()->toArray(false)['detail']);
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function json(array $data): string
    {
        return json_encode($data, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
    }
}
