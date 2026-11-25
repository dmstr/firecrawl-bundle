<?php

// file generated with AI assistance: Claude Code - 2026-11-05 12:00:00 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\Tests\Unit\Connection;

use Dmstr\Firecrawl\Connection\FileConverterHealthClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Health check of a `file-converter` connection: `GET /health` with status ok,
 * anything else is an error, and it never throws.
 */
final class FileConverterHealthClientTest extends TestCase
{
    public function testAnOkAnswerIsHealthy(): void
    {
        $url = null;
        $client = new MockHttpClient(function (string $method, string $requested) use (&$url): MockResponse {
            $url = $requested;

            return new MockResponse('{"status":"ok"}');
        });

        $health = (new FileConverterHealthClient(['base_url' => 'http://converter:8000/'], $client))->getHealthInfo();

        self::assertSame('ok', $health['status']);
        self::assertSame('http://converter:8000/health', $url);
    }

    public function testAnotherAnswerOrNoAnswerIsAnError(): void
    {
        $wrong = new MockHttpClient(new MockResponse('{"status":"starting"}', ['http_code' => 503]));
        $down = new MockHttpClient(static fn (): MockResponse => new MockResponse('', ['error' => 'connection refused']));

        self::assertSame('error', (new FileConverterHealthClient(['base_url' => 'http://converter:8000'], $wrong))->getHealthInfo()['status']);
        self::assertSame('error', (new FileConverterHealthClient(['base_url' => 'http://converter:8000'], $down))->getHealthInfo()['status']);
    }

    public function testAConfigurationWithoutUrlIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new FileConverterHealthClient([], new MockHttpClient());
    }
}
