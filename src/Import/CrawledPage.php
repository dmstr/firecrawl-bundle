<?php

// file generated with AI assistance: Claude Code - 2026-10-03 07:29:38 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\Import;

/**
 * One page of a Firecrawl crawl or batch scrape result, ready for
 * {@see PageImporterInterface::import()}. Built by {@see ResultReader}.
 */
final readonly class CrawledPage
{
    public function __construct(
        /** Normalised URL (fragment removed, scheme and host lower-cased, trailing slash dropped except root, query kept): the match key, stored as the document's source URL. */
        public string $url,
        /** The page Markdown AFTER setext normalisation, stored byte for byte; the content hash is sha256 of these bytes. */
        public string $markdown,
        public ?string $title = null,
        public ?int $statusCode = null,
    ) {
    }
}
