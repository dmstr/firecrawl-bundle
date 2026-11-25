<?php

// file generated with AI assistance: Claude Code - 2026-10-22 08:45:00 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\Tests\Unit\Crawl;

use Dmstr\Firecrawl\Crawl\FileConverter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The call to the converter container: what is sent and how its answers,
 * including its refusals, are turned into a result or a readable error.
 */
final class FileConverterTest extends TestCase
{
    public function testSendsTheFileAndTheOptionsAndReturnsTheMarkdown(): void
    {
        $seen = [];
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen = ['method' => $method, 'url' => $url, 'body' => $options['body'], 'headers' => $options['headers']];

            return new MockResponse(json_encode(['markdown' => '# Titel', 'title' => 'T', 'converter' => 'markitdown', 'pages' => 3], \JSON_THROW_ON_ERROR));
        });

        $result = (new FileConverter($client))->convert(['base_url' => 'http://converter:8000/'], '%PDF-bytes', 'pdf', 'markitdown', true);

        self::assertSame(['markdown' => '# Titel', 'title' => 'T', 'converter' => 'markitdown', 'pages' => 3, 'route' => null], $result);
        self::assertSame('POST', $seen['method']);
        self::assertSame('http://converter:8000/convert', $seen['url']);
        self::assertStringContainsString('name="ext"'."\r\n\r\n".'pdf', $seen['body']);
        self::assertStringContainsString('name="page_markers"'."\r\n\r\n".'true', $seen['body']);
        self::assertStringContainsString('name="ocr"'."\r\n\r\n".'false', $seen['body']);
        self::assertStringContainsString("\r\n\r\n%PDF-bytes\r\n", $seen['body']);
    }

    public function testNoConverterNameAndNoPagesAreOptional(): void
    {
        $client = new MockHttpClient(new MockResponse('{"markdown":"a","title":null,"converter":"pandas","pages":null}'));

        $result = (new FileConverter($client))->convert(['base_url' => 'http://converter:8000'], 'a;b', 'csv');

        self::assertNull($result['title']);
        self::assertNull($result['pages']);
        self::assertSame('pandas', $result['converter']);
    }

    public function testTheOcrSwitchIsSentAndTheRouteReturned(): void
    {
        $body = '';
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$body): MockResponse {
            $body = $options['body'];

            return new MockResponse('{"markdown":"\u00a7 1 Name","title":null,"converter":"markitdown","pages":2,"route":"tesseract (p1 of 2)"}');
        });

        $result = (new FileConverter($client))->convert(['base_url' => 'http://converter:8000'], '%PDF', 'pdf', null, true, true);

        self::assertStringContainsString('name="ocr"'."\r\n\r\n".'true', $body);
        self::assertSame('tesseract (p1 of 2)', $result['route']);
    }

    public function testARefusalBecomesTheReadableReason(): void
    {
        $client = new MockHttpClient(new MockResponse('{"detail":"Conversion with markitdown failed: boom"}', ['http_code' => 422]));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Conversion with markitdown failed: boom');

        (new FileConverter($client))->convert(['base_url' => 'http://converter:8000'], 'x', 'pdf');
    }

    public function testAnUnreachableConverterIsReported(): void
    {
        $client = new MockHttpClient(static fn (): MockResponse => new MockResponse('', ['error' => 'connection refused']));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Converter not reachable');

        (new FileConverter($client))->convert(['base_url' => 'http://converter:8000'], 'x', 'pdf');
    }
}

// - revised 2026-11-17
