<?php

// file generated with AI assistance: Claude Code - 2026-10-03 07:30:27 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\Import;

/**
 * A result entry that cannot be imported (no Markdown, no usable source
 * URL, URL too long). Yielded by {@see ResultReader} next to
 * {@see CrawledPage}; the importer counts it as a failed page.
 */
final readonly class RejectedPage
{
    public function __construct(
        /** Normalised URL when there is one, otherwise the raw source URL, or null when the entry has none. */
        public ?string $url,
        public string $reason,
    ) {
    }
}
