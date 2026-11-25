<?php

// file generated with AI assistance: Claude Code - 2026-10-19 13:21:25 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\Crawl;

/**
 * Sorts what a Firecrawl crawl returned into pages, document files and broken
 * links, with the filters of the profile (domains, URL patterns, file
 * extensions). Firecrawl has no notion of these groups, so this is our code.
 *
 * A page is a crawled HTML result below HTTP 400 that is in scope
 * ({@see isHtml()}: a download such as an image or a PostScript file is
 * no page, whatever its URL looks like). A file is a link
 * seen on such a page whose URL has a document extension (or a download hint).
 * A broken link is a crawled result with HTTP 400 or higher, with the first
 * page that linked to it.
 */
final class UrlClassifier
{
    private readonly string $fileRegex;

    private const array CONTENT_TYPES = [
        'application/pdf' => 'pdf',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.ms-excel' => 'xls',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'application/vnd.ms-powerpoint' => 'ppt',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
        'application/rtf' => 'rtf',
        'application/vnd.oasis.opendocument.text' => 'odt',
        'application/vnd.oasis.opendocument.spreadsheet' => 'ods',
        'text/csv' => 'csv',
    ];

    public function __construct(private readonly CrawlProfile $profile)
    {
        $alternation = implode('|', array_map(static fn (string $e): string => preg_quote(ltrim($e, '.'), '~'), $profile->fileExtensions ?: ['pdf']));
        $this->fileRegex = '~\.('.$alternation.')(?:[^a-z0-9]|$)~i';
    }

    public static function withoutFragment(string $url): string
    {
        $pos = strpos($url, '#');

        return false === $pos ? $url : substr($url, 0, $pos);
    }

    /**
     * Whether a scraped result is a web page by Firecrawl's `contentType`;
     * a result without one counts as HTML.
     *
     * @param array<string, mixed> $metadata
     */
    public static function isHtml(array $metadata): bool
    {
        $type = strtolower((string) ($metadata['contentType'] ?? ''));

        return '' === $type || str_contains($type, 'html');
    }

    public function isFile(string $url): bool
    {
        if (1 === preg_match($this->fileRegex, $url)) {
            return true;
        }
        foreach ($this->profile->opaqueDownloadHints as $hint) {
            if (str_contains($url, $hint)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The document extension of a file URL (`pdf` for `…/a.pdf,p0` too), null if the URL has none of the profile's.
     * Without one in the path, a query value counts, for download endpoints like `…/api?action=download&path=%2Fa.pdf`.
     */
    public function fileExtension(string $url): ?string
    {
        parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
        foreach ([(string) parse_url($url, \PHP_URL_PATH), ...array_filter($query, 'is_string')] as $candidate) {
            if (1 === preg_match($this->fileRegex, $candidate, $m)) {
                return strtolower($m[1]);
            }
        }

        return null;
    }

    /**
     * The document extension of a downloaded file from its response headers, for a download URL without one:
     * the file name in `Content-Disposition`, else the `Content-Type`. Null for anything else, such as an
     * HTML page (a login page when the session expired).
     *
     * @param array<string, list<string>> $headers
     */
    public function fileExtensionFromHeaders(array $headers): ?string
    {
        $name = self::fileName($headers);
        if (null !== $name && null !== ($ext = $this->fileExtension('/'.$name))) {
            return $ext;
        }
        $ext = self::CONTENT_TYPES[strtolower(trim(explode(';', $headers['content-type'][0] ?? '')[0]))] ?? null;

        return null !== $ext && null !== $this->fileExtension('/file.'.$ext) ? $ext : null;
    }

    /**
     * The file name in a `Content-Disposition` header, null without one.
     *
     * @param array<string, list<string>> $headers
     */
    public static function fileName(array $headers): ?string
    {
        $header = $headers['content-disposition'][0] ?? '';
        // RFC 6266: filename* (charset'language'percent-encoded) wins over the plain fallback
        if (1 === preg_match('/filename\*\s*=\s*([\w!#$%&+^`{}~-]*)\'[^\']*\'([^\s;]+)/i', $header, $m)) {
            $name = rawurldecode($m[2]);
            if ('' !== $m[1] && 'UTF-8' !== strtoupper($m[1])) {
                $name = mb_convert_encoding($name, 'UTF-8', $m[1]);
            }

            return $name;
        }

        return 1 === preg_match('/filename\s*=\s*(?:"([^"]*)"|([^\s;]+))/i', $header, $m) ? (($m[2] ?? '') ?: $m[1]) : null;
    }

    /**
     * Whether the session cookie may go to this URL: its host is one of the allowed domains, not just a file domain.
     */
    public function isSiteHost(string $url): bool
    {
        return $this->inScope($url, false);
    }

    public function inScope(string $url, bool $fileDomains = false): bool
    {
        if (!preg_match('~^https?://~i', $url)) {
            return false;
        }
        $host = strtolower((string) parse_url($url, \PHP_URL_HOST));
        $domains = $fileDomains ? [...$this->profile->allowedDomains, ...$this->profile->fileDomains] : $this->profile->allowedDomains;
        if ([] !== $this->profile->allowedDomains && !$this->hostIn($host, $domains)) {
            return false;
        }
        if ($this->hostIn($host, $this->profile->excludedDomains, false)) {
            return false;
        }
        foreach ($this->profile->excludedUrlPatterns as $pattern) {
            $glob = str_contains($pattern, '*') || str_contains($pattern, '?');
            if ($glob ? fnmatch($pattern, $url) : str_contains($url, $pattern)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<array<string, mixed>> $results `data` entries of the crawl(s): `metadata`, `links`
     *
     * @return array{pages: list<string>, files: list<array{url: string, text: string}>, broken: list<array{status: int, url: string, linkedFrom: string}>}
     */
    public function classify(array $results): array
    {
        $pages = [];
        $broken = [];
        $files = [];
        $linkedFrom = [];

        foreach ($results as $result) {
            $meta = \is_array($result['metadata'] ?? null) ? $result['metadata'] : [];
            $url = self::withoutFragment((string) ($meta['url'] ?? $meta['sourceURL'] ?? ''));
            if ('' === $url) {
                continue;
            }
            foreach ((array) ($result['links'] ?? []) as $link) {
                $href = self::withoutFragment((string) (\is_array($link) ? ($link['href'] ?? '') : $link));
                if ('' !== $href) {
                    $linkedFrom[$href] ??= $url;
                }
            }
        }

        foreach ($results as $result) {
            $meta = \is_array($result['metadata'] ?? null) ? $result['metadata'] : [];
            $url = self::withoutFragment((string) ($meta['url'] ?? $meta['sourceURL'] ?? ''));
            if ('' === $url || !$this->inScope($url) || $this->isFile($url)) {
                continue;
            }
            $status = (int) ($meta['statusCode'] ?? 200);
            if ($status >= 400) {
                $broken[$url] = ['status' => $status, 'url' => $url, 'linkedFrom' => $linkedFrom[$url] ?? ''];
                continue;
            }
            if (!self::isHtml($meta)) {
                continue;
            }
            $pages[$url] = true;

            foreach ((array) ($result['links'] ?? []) as $link) {
                $href = self::withoutFragment((string) (\is_array($link) ? ($link['href'] ?? '') : $link));
                if ('' !== $href && $this->inScope($href, true) && $this->isFile($href)) {
                    $files[$href] ??= \is_array($link) ? trim((string) ($link['text'] ?? '')) : '';
                }
            }
        }

        $pageList = array_keys($pages);
        sort($pageList);
        ksort($files);
        ksort($broken);

        return [
            'pages' => $pageList,
            'files' => array_map(static fn (string $url, string $text): array => ['url' => $url, 'text' => $text], array_keys($files), $files),
            'broken' => array_values($broken),
        ];
    }

    /**
     * @param list<string> $domains
     */
    private function hostIn(string $host, array $domains, bool $subdomains = true): bool
    {
        foreach ($domains as $domain) {
            $domain = strtolower($domain);
            if ($host === $domain || ($subdomains && str_ends_with($host, '.'.$domain))) {
                return true;
            }
        }

        return false;
    }
}

// - revised 2026-11-18
// - revised 2026-11-23
