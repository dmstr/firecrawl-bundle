<?php

// file generated with AI assistance: Claude Code - 2026-10-03 07:29:38 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\Import;

/**
 * Result of importing one page ({@see PageImporterInterface::import()}):
 * created, replaced, skipped (unchanged and embedded) or failed with a
 * reason. {@see ResultReader} also emits failed outcomes for result entries
 * that cannot become a {@see CrawledPage} (no Markdown, no usable URL).
 */
final readonly class PageOutcome
{
    public const string CREATED = 'created';
    public const string REPLACED = 'replaced';
    public const string SKIPPED = 'skipped';
    public const string FAILED = 'failed';

    private function __construct(
        public string $status,
        /** UUID of the created, replaced, skipped or conflicting document when known. */
        public ?string $documentId,
        public ?string $reason,
    ) {
    }

    public static function created(string $documentId): self
    {
        return new self(self::CREATED, $documentId, null);
    }

    public static function replaced(string $documentId): self
    {
        return new self(self::REPLACED, $documentId, null);
    }

    public static function skipped(string $documentId): self
    {
        return new self(self::SKIPPED, $documentId, null);
    }

    public static function failed(string $reason, ?string $documentId = null): self
    {
        return new self(self::FAILED, $documentId, $reason);
    }

    public function isFailed(): bool
    {
        return self::FAILED === $this->status;
    }
}
