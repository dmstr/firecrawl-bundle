<?php

// file generated with AI assistance: Claude Code - 2026-10-22 08:45:00 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\Tests\Unit\Crawl;

use Dmstr\Firecrawl\Crawl\FileDownloader;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Downloading a file with the session cookie: the cookie stays with the
 * site's host, redirects are followed by hand, errors and size are checked.
 */
final class FileDownloaderTest extends TestCase
{
    public function testSendsTheCookieAndReturnsTheBytes(): void
    {
        $headers = [];
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$headers): MockResponse {
            $headers = $options['normalized_headers']['cookie'] ?? [];

            return new MockResponse('PDFBYTES');
        });

        self::assertSame('PDFBYTES', (new FileDownloader($client))->download('https://example.org/a.pdf', 'sid=1', 100));
        self::assertSame(['Cookie: sid=1'], $headers);
    }

    public function testReturnsTheHeadersOfTheFinalResponse(): void
    {
        $client = new MockHttpClient([
            new MockResponse('', ['http_code' => 302, 'response_headers' => ['location' => '/files/1']]),
            new MockResponse('PDFBYTES', ['response_headers' => ['content-type' => 'application/pdf']]),
        ]);

        (new FileDownloader($client))->download('https://example.org/download?id=1', null, 100, $headers);
        self::assertSame(['application/pdf'], $headers['content-type']);
    }

    public function testTheCookieIsNotSentToAnotherHostAfterARedirect(): void
    {
        $cookies = [];
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$cookies): MockResponse {
            $cookies[$url] = $options['normalized_headers']['cookie'] ?? null;

            return str_starts_with($url, 'https://example.org/')
                ? new MockResponse('', ['http_code' => 302, 'response_headers' => ['Location: https://cdn.example.net/a.pdf']])
                : new MockResponse('PDFBYTES');
        });

        self::assertSame('PDFBYTES', (new FileDownloader($client))->download('https://example.org/a.pdf', 'sid=1', 100));
        self::assertSame(['Cookie: sid=1'], $cookies['https://example.org/a.pdf']);
        self::assertNull($cookies['https://cdn.example.net/a.pdf']);
    }

    public function testAnErrorStatusIsReported(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('HTTP 404');

        (new FileDownloader(new MockHttpClient(new MockResponse('', ['http_code' => 404]))))->download('https://example.org/a.pdf', null, 100);
    }

    public function testTooLargeAFileIsRefused(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('larger than 5 bytes');

        (new FileDownloader(new MockHttpClient(new MockResponse('0123456789'))))->download('https://example.org/a.pdf', null, 5);
    }

    public function testEndlessRedirectsStop(): void
    {
        $client = new MockHttpClient(static fn (): MockResponse => new MockResponse('', ['http_code' => 302, 'response_headers' => ['Location: /loop']]));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('too many redirects');

        (new FileDownloader($client))->download('https://example.org/a.pdf', null, 100);
    }
}
