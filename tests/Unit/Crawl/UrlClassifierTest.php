<?php

// file generated with AI assistance: Claude Code - 2026-10-20 15:33:11 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\Tests\Unit\Crawl;

use Dmstr\Firecrawl\Crawl\CrawlProfile;
use Dmstr\Firecrawl\Crawl\UrlClassifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Scope, file detection and the sorting of crawl results into pages, files
 * and broken links.
 */
final class UrlClassifierTest extends TestCase
{
    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function scope(): iterable
    {
        yield 'allowed host' => ['https://example.org/a', true];
        yield 'subdomain of an allowed host' => ['https://www.example.org/a', true];
        yield 'other host' => ['https://other.org/a', false];
        yield 'host that only ends like an allowed one' => ['https://badexample.org/a', false];
        yield 'excluded subdomain' => ['https://intern.example.org/a', false];
        yield 'excluded by substring' => ['https://example.org/user/logout', false];
        yield 'excluded by glob' => ['https://example.org/print/page?x=1', false];
        yield 'mailto' => ['mailto:info@example.org', false];
        yield 'relative' => ['/a', false];
    }

    #[DataProvider('scope')]
    public function testInScope(string $url, bool $expected): void
    {
        self::assertSame($expected, self::classifier()->inScope($url));
    }

    public function testWithoutAllowedDomainsEveryHostIsInScope(): void
    {
        self::assertTrue(self::classifier(allowed: [])->inScope('https://other.org/a'));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function files(): iterable
    {
        yield 'pdf' => ['https://example.org/doc/manual.PDF', true];
        yield 'extension before a query' => ['https://example.org/sheet.xlsx?v=2', true];
        yield 'download hint' => ['https://example.org/download/123', true];
        yield 'page' => ['https://example.org/pdf-overview', false];
        yield 'extension as a prefix of a word' => ['https://example.org/file.pdfx', false];
    }

    #[DataProvider('files')]
    public function testIsFile(string $url, bool $expected): void
    {
        self::assertSame($expected, self::classifier()->isFile($url));
    }

    public function testWithoutFragment(): void
    {
        self::assertSame('https://example.org/a', UrlClassifier::withoutFragment('https://example.org/a#top'));
        self::assertSame('https://example.org/a', UrlClassifier::withoutFragment('https://example.org/a'));
    }

    public function testClassify(): void
    {
        $results = [
            ['metadata' => ['url' => 'https://example.org/b', 'statusCode' => 200], 'links' => [
                'https://example.org/missing#x',
                ['href' => 'https://example.org/doc/manual.pdf', 'text' => ' Manual '],
                'https://other.org/report.pdf',
            ]],
            ['metadata' => ['sourceURL' => 'https://example.org/a#top', 'statusCode' => 200], 'links' => ['https://example.org/doc/manual.pdf']],
            ['metadata' => ['url' => 'https://example.org/missing', 'statusCode' => 404], 'links' => []],
            ['metadata' => ['url' => 'https://other.org/x', 'statusCode' => 200], 'links' => []],
            ['metadata' => [], 'links' => []],
        ];

        self::assertSame([
            'pages' => ['https://example.org/a', 'https://example.org/b'],
            'files' => [['url' => 'https://example.org/doc/manual.pdf', 'text' => 'Manual']],
            'broken' => [['status' => 404, 'url' => 'https://example.org/missing', 'linkedFrom' => 'https://example.org/b']],
        ], self::classifier()->classify($results));
    }

    /**
     * @param list<string> $allowed
     */
    public function testADownloadThatIsNoHtmlIsNoPage(): void
    {
        $result = self::classifier()->classify([
            ['metadata' => ['url' => 'https://example.org/p', 'statusCode' => 200, 'contentType' => 'text/html; charset=UTF-8'], 'links' => []],
            ['metadata' => ['url' => 'https://example.org/logo.eps,p0', 'statusCode' => 200, 'contentType' => 'application/postscript'], 'links' => []],
        ]);

        self::assertSame(['https://example.org/p'], $result['pages']);
        self::assertFalse(UrlClassifier::isHtml(['contentType' => 'image/png']));
        self::assertTrue(UrlClassifier::isHtml(['contentType' => 'application/xhtml+xml']));
        self::assertTrue(UrlClassifier::isHtml([]));
    }

    public function testFileExtensionAlsoBeforeAProxySuffix(): void
    {
        $c = self::classifier();

        self::assertSame('pdf', $c->fileExtension('https://img.example.net/https://example.org/a/katalog.pdf,p0'));
        self::assertSame('xlsx', $c->fileExtension('https://example.org/Sheet.XLSX?v=2'));
        self::assertSame('pdf', $c->fileExtension('https://example.org/api?action=download&path=%2Fdocs%2Fflyer.pdf'));
        self::assertNull($c->fileExtension('https://example.org/download/123'));
        self::assertNull($c->fileExtension('https://example.org/download?id=123&name=report'));
    }

    public function testFileExtensionOfADownloadUrlFromTheResponseHeaders(): void
    {
        $c = self::classifier();

        self::assertSame('xlsx', $c->fileExtensionFromHeaders(['content-disposition' => ['attachment; filename="Liste Kalkung.xlsx"'], 'content-type' => ['application/octet-stream']]));
        self::assertNull($c->fileExtensionFromHeaders(['content-disposition' => ['attachment; filename="Merkblatt.docx"']]), 'not a file extension of the profile');
        self::assertSame('pdf', $c->fileExtensionFromHeaders(['content-disposition' => ["attachment; filename*=UTF-8''F%C3%B6rderung.pdf"]]));
        self::assertSame('xlsx', $c->fileExtensionFromHeaders(['content-type' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']]));
        self::assertSame('pdf', $c->fileExtensionFromHeaders(['content-type' => ['application/pdf; charset=binary']]));
        self::assertNull($c->fileExtensionFromHeaders(['content-type' => ['text/html; charset=UTF-8']]));
        self::assertSame('Förderung.pdf', UrlClassifier::fileName(['content-disposition' => ["attachment; filename*=UTF-8''F%C3%B6rderung.pdf"]]));
        self::assertSame('Bericht März.pdf', UrlClassifier::fileName(['content-disposition' => ["attachment; filename=\"Bericht.pdf\"; filename*=UTF-8''Bericht%20M%C3%A4rz.pdf"]]), 'filename* wins over the fallback');
        self::assertSame('März.pdf', UrlClassifier::fileName(['content-disposition' => ["attachment; filename*=ISO-8859-1''M%E4rz.pdf"]]));
        self::assertSame('100%25.pdf', UrlClassifier::fileName(['content-disposition' => ['attachment; filename="100%25.pdf"']]), 'a plain filename is not percent-decoded');
        self::assertSame('a.pdf', UrlClassifier::fileName(['content-disposition' => ['attachment; filename=a.pdf']]));
        self::assertNull(UrlClassifier::fileName(['content-disposition' => ['inline']]));
    }

    public function testAFileOnAFileDomainIsListedButTheCookieStaysAtHome(): void
    {
        $c = self::classifier(fileDomains: ['img.example.net']);
        $link = 'https://img.example.net/https://example.org/a/katalog.pdf,p0';

        $result = $c->classify([['metadata' => ['url' => 'https://example.org/p', 'statusCode' => 200], 'links' => [$link, 'https://other.org/x.pdf']]]);

        self::assertSame([['url' => $link, 'text' => '']], $result['files']);
        self::assertFalse($c->isSiteHost($link));
        self::assertTrue($c->isSiteHost('https://example.org/a.pdf'));
        self::assertFalse(self::classifier()->inScope($link), 'a file host is not a page host');
    }

    private static function classifier(array $allowed = ['example.org'], array $fileDomains = []): UrlClassifier
    {
        return new UrlClassifier(new CrawlProfile(
            name: 'site',
            seeds: ['https://example.org/'],
            allowedDomains: $allowed,
            excludedDomains: ['intern.example.org'],
            excludedUrlPatterns: ['/logout', '*/print/*'],
            maxDepth: 3,
            limit: 100,
            fileExtensions: ['pdf', '.xlsx'],
            opaqueDownloadHints: ['/download/'],
            excludedTags: [],
            excludedSelector: '',
            auth: null,
            fileDomains: $fileDomains,
        ));
    }
}

// - revised 2026-11-23
