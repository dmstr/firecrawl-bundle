<?php

// file generated with AI assistance: Claude Code - 2026-10-20 15:33:11 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\Tests\Unit\Crawl;

use Dmstr\Firecrawl\Crawl\PageFilename;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * File names of crawled pages: the path as lower-case words, a query hash,
 * and a URL hash for the second URL that maps to a name already taken.
 */
final class PageFilenameTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function urls(): iterable
    {
        yield 'path with .html' => ['https://example.org/de-de/p/anwendungen/briefschaften-54.html', 'de-de-p-anwendungen-briefschaften-54.md'];
        yield 'root' => ['https://example.org/', 'index.md'];
        yield 'no path' => ['https://example.org', 'index.md'];
        yield 'encoded umlaut and case' => ['https://example.org/%C3%9Cber/Uns', 'ber-uns.md'];
        yield 'query' => ['https://example.org/list?page=2', 'list-'.substr(md5('page=2'), 0, 6).'.md'];
        yield 'query only' => ['https://example.org/?page=2', substr(md5('page=2'), 0, 6).'.md'];
    }

    #[DataProvider('urls')]
    public function testFromUrl(string $url, string $expected): void
    {
        self::assertSame($expected, PageFilename::fromUrl($url));
    }

    public function testUniqueFromUrlHashesTheSecondUrlOfTheSameName(): void
    {
        $used = [];

        self::assertSame('a-b.md', PageFilename::uniqueFromUrl('https://example.org/a/b', $used));
        self::assertSame('a-b-'.substr(md5('https://example.org/a-b'), 0, 6).'.md', PageFilename::uniqueFromUrl('https://example.org/a-b', $used));
        self::assertSame('c.md', PageFilename::uniqueFromUrl('https://example.org/c.html', $used));
        self::assertCount(3, $used);
    }
}
