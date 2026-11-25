<?php

// file generated with AI assistance: Claude Code - 2026-10-19 13:49:00 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\Crawl;

/**
 * Deterministic file name of a crawled page: the path of its URL in lower-case
 * words, `.html` dropped, a short hash of the query string if there is one.
 * `/de-de/p/anwendungen/briefschaften-54.html` becomes
 * `de-de-p-anwendungen-briefschaften-54.md`.
 */
final class PageFilename
{
    public static function fromUrl(string $url): string
    {
        $path = rawurldecode((string) parse_url($url, \PHP_URL_PATH));
        $path = (string) preg_replace('~\.html?$~i', '', strtolower($path));
        $slug = trim((string) preg_replace('~[^a-z0-9]+~', '-', $path), '-');
        $query = (string) parse_url($url, \PHP_URL_QUERY);

        if ('' !== $query) {
            $slug .= ('' === $slug ? '' : '-').substr(md5($query), 0, 6);
        }

        return ('' === $slug ? 'index' : $slug).'.md';
    }

    /**
     * {@see fromUrl()}, unless another URL of the same run took that name
     * already (`/a/b` and `/a-b`, `/Foo` and `/foo`, `/x` and `/x.html`):
     * then a short hash of the URL is appended. $used collects the names.
     *
     * @param array<string, true> $used
     */
    public static function uniqueFromUrl(string $url, array &$used): string
    {
        $file = self::fromUrl($url);
        if (isset($used[$file])) {
            $file = substr($file, 0, -3).'-'.substr(md5($url), 0, 6).'.md';
        }
        $used[$file] = true;

        return $file;
    }
}

// - revised 2026-10-20
