<?php

// file generated with AI assistance: Claude Code - 2026-10-03 00:27:44 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\Tests\Unit\Live;

use Dmstr\Firecrawl\Client\FirecrawlClientFactory;
use Dmstr\Firecrawl\Client\FirecrawlClientInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\HttpClient;

/**
 * Smoke test against a real Firecrawl instance: lists the active crawls
 * and scrapes https://example.com/ as Markdown. It needs no kernel and no
 * database; the client is built through the production
 * {@see FirecrawlClientFactory} on Symfony's HttpClient.
 *
 * It is skipped unless both FIRECRAWL_LIVE_URL and FIRECRAWL_LIVE_API_KEY
 * are set, so it never runs in CI (the variables are not defined there) and
 * self-skips in the default run:
 *
 *     FIRECRAWL_LIVE_URL=https://firecrawl.example.org FIRECRAWL_LIVE_API_KEY=fc-... \
 *         vendor/bin/phpunit --group live
 */
#[Group('live')]
final class FirecrawlLiveTest extends TestCase
{
    private FirecrawlClientInterface $client;

    protected function setUp(): void
    {
        $url = trim((string) getenv('FIRECRAWL_LIVE_URL'));
        $apiKey = trim((string) getenv('FIRECRAWL_LIVE_API_KEY'));
        if ('' === $url || '' === $apiKey) {
            self::markTestSkipped('Live Firecrawl test: set FIRECRAWL_LIVE_URL and FIRECRAWL_LIVE_API_KEY to run it (never in CI).');
        }

        $this->client = (new FirecrawlClientFactory(HttpClient::create()))->create([
            'firecrawl' => true,
            'type' => 'firecrawl',
            'endpoint_type' => 'rest',
            'base_url' => $url,
            'api_key' => $apiKey,
            'timeout' => 55,
        ]);
    }

    public function testActiveCrawlsAreListed(): void
    {
        $answer = $this->client->activeCrawls();

        self::assertArrayHasKey('crawls', $answer);
        self::assertIsList($answer['crawls']);
    }

    public function testScrapeReturnsMarkdown(): void
    {
        $answer = $this->client->scrape(['url' => 'https://example.com/', 'formats' => ['markdown']]);

        self::assertTrue($answer['success'] ?? false);
        self::assertIsString($answer['data']['markdown'] ?? null);
        self::assertNotSame('', trim($answer['data']['markdown']));
    }
}
