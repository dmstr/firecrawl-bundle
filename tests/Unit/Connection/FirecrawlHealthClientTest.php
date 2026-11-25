<?php

// file generated with AI assistance: Claude Code - 2026-10-05 13:30:00 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\Tests\Unit\Connection;

use Dmstr\ApiConfiguration\ApiClient\AuthenticationFailedException;
use Dmstr\ApiConfiguration\Normalizer\HealthNormalizer;
use Dmstr\Firecrawl\Client\FirecrawlApiException;
use Dmstr\Firecrawl\Client\FirecrawlProtocolException;
use Dmstr\Firecrawl\Connection\FirecrawlExtension;
use Dmstr\Firecrawl\Connection\FirecrawlHealthClient;
use Dmstr\Firecrawl\Testing\FakeFirecrawlClientFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;

/**
 * Kernel-free checks of the health probe of `firecrawl` connections against
 * the fake client factory. Every result is also run through the platform's
 * HealthNormalizer, which validates it against health.json.
 */
final class FirecrawlHealthClientTest extends TestCase
{
    private const string BASE_URL = 'https://firecrawl.example.org';
    private const string ACTIVE = '/v2/crawl/active';

    private FakeFirecrawlClientFactory $fake;

    protected function setUp(): void
    {
        $this->fake = new FakeFirecrawlClientFactory();
    }

    public function testCreateClientPerformsNoIo(): void
    {
        $client = $this->client();

        self::assertSame(self::BASE_URL, $client->getEndpoint());
        self::assertSame('firecrawl', $client->getApiName());
        self::assertSame('rest', $client->getType());
        self::assertSame(0, $this->fake->getClientCount());
        self::assertSame(0, $this->fake->getRequestCount());
    }

    public function testHealthyInstanceReportsActiveCrawls(): void
    {
        $this->fake->serve('GET', self::ACTIVE, ['success' => true, 'crawls' => [
            ['id' => 'a', 'teamId' => 't', 'url' => 'https://example.org', 'options' => []],
            ['id' => 'b', 'teamId' => 't', 'url' => 'https://example.com', 'options' => []],
        ]]);

        $health = $this->client()->getHealthInfo();

        self::assertSame('ok', $health['status']);
        self::assertTrue($health['authenticated']);
        self::assertSame('Firecrawl reachable, 2 active crawl(s).', $health['message']);
        self::assertSame(['activeCrawls' => 2], $health['metadata']['custom']);
        self::assertArrayNotHasKey('error', $health);
        self::assertSame(1, $this->fake->getClientCount());
        self::assertSame(1, $this->fake->getRequestCount(self::BASE_URL, 'GET', self::ACTIVE));
        self::assertSame('fc-secret', $this->fake->getCreatedConfigs()[0]['api_key']);

        $normalized = $this->normalize($health);
        self::assertSame(self::BASE_URL, $normalized['endpoint']);
        self::assertSame(2, $normalized['metadata']['custom']['activeCrawls']);
    }

    public function testAuthenticateMirrorsTheProbe(): void
    {
        $this->fake->serve('GET', self::ACTIVE, ['success' => true, 'crawls' => []]);
        $this->client()->authenticate();

        $this->fake->rejectCredentials();
        $this->expectException(AuthenticationFailedException::class);
        $this->client()->authenticate();
    }

    /**
     * @return iterable<string, array{\Throwable}>
     */
    public static function unauthenticated(): iterable
    {
        yield '401 json' => [new FirecrawlApiException('HTTP 401', 401, '{"success":false,"error":"Unauthorized: Invalid token"}')];
        yield '401 plain text' => [new FirecrawlApiException('HTTP 401', 401, 'Unauthorized')];
        yield '403 plain text' => [new FirecrawlApiException('HTTP 403', 403, 'Forbidden')];
        yield '403 json' => [new FirecrawlApiException('HTTP 403', 403, '{"success":false,"error":"Forbidden"}')];
        yield '403 empty body' => [new FirecrawlApiException('', 403, '')];
        yield 'wrapped 403' => [new \RuntimeException('outer', 0, new FirecrawlApiException('HTTP 403', 403, 'Forbidden'))];
    }

    #[DataProvider('unauthenticated')]
    public function testRejectedCredentialsAreUnauthenticated(\Throwable $exception): void
    {
        $this->fake->queueException('GET', self::ACTIVE, $exception);

        $health = $this->client()->getHealthInfo();

        self::assertSame('error', $health['status']);
        self::assertFalse($health['authenticated']);
        self::assertSame(FirecrawlHealthClient::CODE_UNAUTHENTICATED, $health['error']['code']);
        self::assertStringStartsWith('Firecrawl rejected the credentials: ', $health['message']);
        $this->normalize($health);
    }

    public function testRejectCredentialsScriptIsUnauthenticated(): void
    {
        $this->fake->rejectCredentials();

        self::assertSame(FirecrawlHealthClient::CODE_UNAUTHENTICATED, $this->client()->getHealthInfo()['error']['code']);
    }

    /**
     * @return iterable<string, array{callable(FakeFirecrawlClientFactory): void}>
     */
    public static function protocol(): iterable
    {
        yield 'html body' => [static fn (FakeFirecrawlClientFactory $f) => $f->queueException('GET', self::ACTIVE, new FirecrawlProtocolException('no JSON object', 200, '<html>'))];
        yield 'json exception' => [static fn (FakeFirecrawlClientFactory $f) => $f->queueException('GET', self::ACTIVE, new \JsonException('Syntax error'))];
        yield 'missing crawls' => [static fn (FakeFirecrawlClientFactory $f) => $f->queue('GET', self::ACTIVE, ['success' => true])];
        yield 'crawls not a list' => [static fn (FakeFirecrawlClientFactory $f) => $f->queue('GET', self::ACTIVE, ['success' => true, 'crawls' => ['a' => 1]])];
    }

    /**
     * @param callable(FakeFirecrawlClientFactory): void $script
     */
    #[DataProvider('protocol')]
    public function testUnusableAnswerIsAProtocolError(callable $script): void
    {
        $script($this->fake);

        $health = $this->client()->getHealthInfo();

        self::assertSame('error', $health['status']);
        self::assertFalse($health['authenticated']);
        self::assertSame(FirecrawlHealthClient::CODE_PROTOCOL, $health['error']['code'], $health['message']);
        self::assertStringStartsWith('Firecrawl protocol error: ', $health['message']);
        $this->normalize($health);
    }

    /**
     * @return iterable<string, array{\Throwable}>
     */
    public static function unreachable(): iterable
    {
        yield 'transport' => [new FirecrawlApiException('Firecrawl GET /v2/crawl/active failed: Connection refused', null, null, new TransportException('Connection refused'))];
        yield 'http 500' => [new FirecrawlApiException('HTTP 500', 500, '{"error":"Internal"}')];
        yield 'http 502' => [new FirecrawlApiException('HTTP 502', 502, 'Bad Gateway')];
        yield 'http 404' => [new FirecrawlApiException('HTTP 404', 404, 'Not Found')];
        yield 'generic' => [new \RuntimeException('Something broke')];
    }

    #[DataProvider('unreachable')]
    public function testOtherFailuresAreUnreachable(\Throwable $exception): void
    {
        $this->fake->failWith($exception);

        $health = $this->client()->getHealthInfo();

        self::assertSame('error', $health['status']);
        self::assertFalse($health['authenticated']);
        self::assertSame(FirecrawlHealthClient::CODE_UNREACHABLE, $health['error']['code']);
        self::assertStringContainsString($exception->getMessage(), $health['message']);
        $this->normalize($health);
    }

    public function testClassify(): void
    {
        self::assertSame('UNAUTHENTICATED', FirecrawlHealthClient::classify(new FirecrawlApiException('x', 401)));
        self::assertSame('UNAUTHENTICATED', FirecrawlHealthClient::classify(new FirecrawlApiException('x', 403, 'Forbidden')));
        self::assertSame('PROTOCOL', FirecrawlHealthClient::classify(new FirecrawlProtocolException('x', 200)));
        self::assertSame('UNREACHABLE', FirecrawlHealthClient::classify(new FirecrawlApiException('x', 503)));
        // Wording alone does not count, only the status code.
        self::assertSame('UNREACHABLE', FirecrawlHealthClient::classify(new \RuntimeException('401 Unauthorized')));
    }

    public function testDomainMethodsAreNoOps(): void
    {
        $client = $this->client();

        self::assertSame([], $client->getProjects());
        self::assertSame([], $client->getTodos('p'));
        self::assertNull($client->getTodo('p', 't'));
        self::assertSame(0, $this->fake->getClientCount());
    }

    public function testConfigurationWithoutBaseUrlIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new FirecrawlExtension($this->fake))->createClient(['type' => 'firecrawl']);
    }

    private function client(): FirecrawlHealthClient
    {
        $client = (new FirecrawlExtension($this->fake))->createClient([
            'firecrawl' => true,
            'type' => 'firecrawl',
            'endpoint_type' => 'rest',
            'base_url' => self::BASE_URL,
            'api_key' => 'fc-secret',
            // Added by ApiClientFactory::createFromEntity().
            '_apiConfigurationId' => '0199a1b2-0000-4000-8000-000000000001',
        ]);
        self::assertInstanceOf(FirecrawlHealthClient::class, $client);

        return $client;
    }

    /**
     * @param array<string, mixed> $health
     *
     * @return array<string, mixed>
     */
    private function normalize(array $health): array
    {
        return (new HealthNormalizer())->normalize($health, self::BASE_URL, 12.5);
    }
}
