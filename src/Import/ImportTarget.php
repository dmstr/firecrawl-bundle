<?php

// file generated with AI assistance: Claude Code - 2026-10-03 07:29:38 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\Import;

/**
 * The collection an import writes into, plus the site its documents are
 * attributed to. Returned by {@see PageImporterInterface::prepare()}; it
 * carries strings only, so the bundle never sees the application's
 * collection entity.
 */
final readonly class ImportTarget
{
    public function __construct(
        /** UUID of the knowledge collection as a string. */
        public string $collectionId,
        public string $collectionName,
        /** Lower-cased host of the crawl's start URL (`origin_site`); null for batch scrapes. */
        public ?string $site,
        /** Chunk sizes of the documents (`chunkTargetSize`, `chunkOverlap`, `chunkMinSize`); empty = the application's defaults. */
        public array $chunkOptions = [],
    ) {
    }

    /**
     * @param array<string, int> $chunkOptions
     */
    public function withChunkOptions(array $chunkOptions): self
    {
        return new self($this->collectionId, $this->collectionName, $this->site, $chunkOptions);
    }
}
