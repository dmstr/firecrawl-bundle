<?php

// file generated with AI assistance: Claude Code - 2026-10-03 07:30:27 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\Import;

/**
 * Normalised page URL, the identity of an imported page (stored as the
 * document's source URL and used as the match key on re-import):
 *  - the fragment is removed;
 *  - scheme and host are lower-cased (`www.` is kept, so `www.example.org`
 *    and `example.org` are different pages);
 *  - a trailing slash of the path is dropped, except for the root path; an
 *    empty path becomes the root `/`;
 *  - port, user info, path case and the query are kept verbatim.
 *
 * Only absolute http(s) URLs with a host are usable; everything else
 * normalises to null.
 */
final class UrlNormalizer
{
    /** Longest normalised URL an import accepts; longer pages count as failed. */
    public const int MAX_LENGTH = 2048;

    public static function normalize(string $url): ?string
    {
        $url = trim($url);
        if ('' === $url) {
            return null;
        }

        $parts = parse_url($url);
        if (false === $parts || !isset($parts['scheme'], $parts['host']) || '' === $parts['host']) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);
        if ('http' !== $scheme && 'https' !== $scheme) {
            return null;
        }

        $userInfo = '';
        if (isset($parts['user'])) {
            $userInfo = $parts['user'].(isset($parts['pass']) ? ':'.$parts['pass'] : '').'@';
        }

        $path = rtrim($parts['path'] ?? '', '/');
        if ('' === $path) {
            $path = '/';
        }

        return $scheme.'://'.$userInfo.strtolower($parts['host'])
            .(isset($parts['port']) ? ':'.$parts['port'] : '')
            .$path
            .(isset($parts['query']) ? '?'.$parts['query'] : '');
    }

    /**
     * Lower-cased host of a URL (the `origin_site` of a crawl); null when
     * the URL has no host.
     */
    public static function host(string $url): ?string
    {
        $host = parse_url(trim($url), \PHP_URL_HOST);

        return \is_string($host) && '' !== $host ? strtolower($host) : null;
    }
}
