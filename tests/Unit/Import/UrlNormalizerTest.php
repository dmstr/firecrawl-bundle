<?php

// file generated with AI assistance: Claude Code - 2026-10-03 07:43:59 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\Tests\Unit\Import;

use Dmstr\Firecrawl\Import\UrlNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Kernel-free checks of the page identity of an import: the normalised URL
 * (fragment removed, scheme and host lower-cased, trailing slash dropped
 * except for the root, `www.` and the query kept) and the site host.
 */
final class UrlNormalizerTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string|null}>
     */
    public static function urls(): iterable
    {
        yield 'already normal' => ['https://example.org/docs/a', 'https://example.org/docs/a'];
        yield 'fragment removed' => ['https://example.org/docs/a#section-2', 'https://example.org/docs/a'];
        yield 'empty fragment removed' => ['https://example.org/docs/a#', 'https://example.org/docs/a'];
        yield 'scheme and host lower-cased' => ['HTTPS://Example.ORG/Docs/A', 'https://example.org/Docs/A'];
        yield 'path case kept' => ['https://example.org/CamelCase', 'https://example.org/CamelCase'];
        yield 'trailing slash dropped' => ['https://example.org/docs/', 'https://example.org/docs'];
        yield 'several trailing slashes dropped' => ['https://example.org/docs//', 'https://example.org/docs'];
        yield 'root keeps its slash' => ['https://example.org/', 'https://example.org/'];
        yield 'empty path becomes root' => ['https://example.org', 'https://example.org/'];
        yield 'root with fragment' => ['https://example.org/#top', 'https://example.org/'];
        yield 'www kept' => ['https://www.example.org/a', 'https://www.example.org/a'];
        yield 'query kept verbatim' => ['https://example.org/a/?b=2&a=1#x', 'https://example.org/a?b=2&a=1'];
        yield 'query on the root' => ['https://example.org?page=2', 'https://example.org/?page=2'];
        yield 'port kept' => ['http://Example.org:8080/a/', 'http://example.org:8080/a'];
        yield 'surrounding whitespace trimmed' => ["  https://example.org/a \n", 'https://example.org/a'];
        yield 'http stays http' => ['http://example.org/a', 'http://example.org/a'];

        yield 'ftp rejected' => ['ftp://example.org/a', null];
        yield 'mailto rejected' => ['mailto:office@example.org', null];
        yield 'relative path rejected' => ['/docs/a', null];
        yield 'no scheme rejected' => ['example.org/docs', null];
        yield 'no host rejected' => ['https:///docs', null];
        yield 'empty rejected' => ['', null];
        yield 'blank rejected' => ['   ', null];
    }

    #[DataProvider('urls')]
    public function testNormalize(string $url, ?string $expected): void
    {
        self::assertSame($expected, UrlNormalizer::normalize($url));
    }

    #[DataProvider('urls')]
    public function testNormalizeIsIdempotent(string $url, ?string $expected): void
    {
        if (null === $expected) {
            self::assertNull(UrlNormalizer::normalize($url));

            return;
        }

        self::assertSame($expected, UrlNormalizer::normalize($expected));
    }

    public function testWwwAndBareHostAreDifferentPages(): void
    {
        self::assertNotSame(UrlNormalizer::normalize('https://www.example.org/a'), UrlNormalizer::normalize('https://example.org/a'));
    }

    public function testVariantsOfOnePageNormaliseAlike(): void
    {
        $variants = ['https://example.org/a', 'https://example.org/a/', 'HTTPS://EXAMPLE.org/a#top', 'https://example.org/a/#'];

        self::assertSame(['https://example.org/a'], array_values(array_unique(array_map(UrlNormalizer::normalize(...), $variants))));
    }

    public function testHost(): void
    {
        self::assertSame('docs.example.org', UrlNormalizer::host('https://Docs.Example.ORG/start'));
        self::assertSame('www.example.org', UrlNormalizer::host(' https://www.example.org '));
        self::assertNull(UrlNormalizer::host('not a url'));
        self::assertNull(UrlNormalizer::host(''));
    }

    public function testMaxLength(): void
    {
        self::assertSame(2048, UrlNormalizer::MAX_LENGTH);
    }
}
