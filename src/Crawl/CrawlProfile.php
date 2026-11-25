<?php

// file generated with AI assistance: Claude Code - 2026-10-19 13:21:25 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\Crawl;

use Symfony\Component\Yaml\Yaml;

/**
 * A crawl profile as the crawl uses it: where to start, what to leave out and
 * how to log in. Built from a stored profile ({@see \Dmstr\Firecrawl\Entity\CrawlProfile},
 * with the decrypted password in `auth.password`) or, for the import, from a
 * YAML file of the same structure.
 */
final readonly class CrawlProfile
{
    /**
     * @param list<string>              $seeds
     * @param list<string>              $allowedDomains
     * @param list<string>              $fileDomains    hosts a document file may be on besides the allowed domains (CDN, image proxy); the cookie is never sent there
     * @param list<string>              $excludedDomains
     * @param list<string>              $excludedUrlPatterns
     * @param list<string>              $fileExtensions
     * @param list<string>              $opaqueDownloadHints
     * @param list<string>              $excludedTags
     * @param array<string, mixed>|null $auth
     * @param array<string, mixed>      $files the `crawl.files` block: whether and how document files are converted
     */
    public function __construct(
        public string $name,
        public array $seeds,
        public array $allowedDomains,
        public array $excludedDomains,
        public array $excludedUrlPatterns,
        public int $maxDepth,
        public int $limit,
        public array $fileExtensions,
        public array $opaqueDownloadHints,
        public array $excludedTags,
        public string $excludedSelector,
        public ?array $auth,
        public array $files = [],
        public array $fileDomains = [],
        public int $waitFor = 0,
    ) {
    }

    public static function fromFile(string $name, string $path): self
    {
        if (!preg_match('/^[a-z0-9][a-z0-9-]*$/', $name) || !is_file($path)) {
            throw new \InvalidArgumentException(\sprintf('Crawl profile "%s" not found (expected %s).', $name, $path));
        }

        $data = Yaml::parseFile($path);
        if (!\is_array($data)) {
            throw new \InvalidArgumentException(\sprintf('Crawl profile "%s" is not a YAML mapping.', $name));
        }

        return self::fromArray($name, $data);
    }

    /**
     * A profile from its structure (`discover`, `crawl`, `auth`), as the YAML file and the `config` of a
     * stored profile have it.
     *
     * @param array<mixed> $data
     *
     * @throws \InvalidArgumentException when the seeds are missing, or a login lacks allowed_domains
     */
    public static function fromArray(string $name, array $data): self
    {
        $discover = $data['discover'] ?? [];
        $web = $data['crawl']['web'] ?? [];

        $seeds = self::strings($discover['seeds'] ?? []);
        if ([] === $seeds) {
            throw new \InvalidArgumentException(\sprintf('Crawl profile "%s" needs discover.seeds.', $name));
        }

        // Without allowed_domains every linked host is in scope, and the
        // session cookie would go to each of them with the scrape.
        $auth = \is_array($data['auth'] ?? null) ? $data['auth'] : null;
        $allowedDomains = self::strings($discover['allowed_domains'] ?? []);
        if (null !== $auth && [] === $allowedDomains) {
            throw new \InvalidArgumentException(\sprintf('Crawl profile "%s" has an auth block and needs discover.allowed_domains, so the session cookie is sent to the site only.', $name));
        }

        return new self(
            name: $name,
            seeds: $seeds,
            allowedDomains: $allowedDomains,
            excludedDomains: self::strings($discover['excluded_domains'] ?? []),
            excludedUrlPatterns: self::strings($discover['excluded_url_patterns'] ?? []),
            maxDepth: (int) ($discover['max_depth'] ?? 3),
            limit: (int) ($discover['limit'] ?? 100),
            fileExtensions: self::strings($discover['file_extensions'] ?? []),
            opaqueDownloadHints: self::strings($discover['opaque_download_hints'] ?? []),
            excludedTags: self::strings($web['excluded_tags'] ?? []),
            excludedSelector: (string) ($web['excluded_selector'] ?? ''),
            auth: $auth,
            files: \is_array($data['crawl']['files'] ?? null) ? $data['crawl']['files'] : [],
            fileDomains: self::strings($discover['file_domains'] ?? []),
            waitFor: max(0, (int) ($web['wait_for'] ?? 0)),
        );
    }

    /**
     * Firecrawl `scrapeOptions.excludeTags`: the tags and the selector of the profile in one list.
     *
     * @return list<string>
     */
    public function excludeTags(): array
    {
        return '' === $this->excludedSelector ? $this->excludedTags : [...$this->excludedTags, $this->excludedSelector];
    }

    /**
     * Document files are only downloaded and converted when the profile asks for it.
     */
    public function convertsFiles(): bool
    {
        return true === ($this->files['enabled'] ?? false);
    }

    /**
     * The converter of the profile for a file extension, null for the converter's own choice.
     */
    public function converterFor(string $ext): ?string
    {
        $converters = \is_array($this->files['converters'] ?? null) ? array_change_key_case($this->files['converters']) : [];
        $converter = $converters[strtolower($ext)] ?? null;

        return \is_string($converter) && '' !== $converter ? strtolower($converter) : null;
    }

    public function pdfPageMarkers(): bool
    {
        return false !== ($this->files['pdf_page_markers'] ?? true);
    }

    /**
     * Pages of a PDF without a text layer (scans) are read by OCR in the converter. Off by default: it is slow.
     */
    public function ocr(): bool
    {
        return true === ($this->files['ocr'] ?? false);
    }

    public function maxFileBytes(): int
    {
        return max(1, (int) ($this->files['max_bytes'] ?? 20 * 1024 * 1024));
    }

    public function withLimit(int $limit): self
    {
        return new self(
            $this->name, $this->seeds, $this->allowedDomains, $this->excludedDomains, $this->excludedUrlPatterns,
            $this->maxDepth, $limit, $this->fileExtensions, $this->opaqueDownloadHints,
            $this->excludedTags, $this->excludedSelector, $this->auth, $this->files, $this->fileDomains, $this->waitFor,
        );
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $value): array
    {
        return array_values(array_filter(array_map('strval', \is_array($value) ? $value : []), static fn (string $s): bool => '' !== $s));
    }
}

// - revised 2026-10-20
// - revised 2026-10-22
// - revised 2026-11-17
