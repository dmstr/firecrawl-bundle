<?php

// file generated with AI assistance: Claude Code - 2026-10-03 07:31:55 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\Import;

/**
 * An import that could not finish ({@see JobImporter::import()}). Carries
 * the report as far as the import got (pages imported before the failure
 * stay imported) and whether a retry could help:
 *  - unrecoverable: the result cannot be read ({@see ResultReaderException}:
 *    not completed, expired or unknown, foreign continuation link) or held
 *    no pages at all;
 *  - recoverable: a transport failure, an exception of the application's
 *    page importer, or every page failed (e.g. the embedding service was
 *    down); a re-run is an upsert and skips the pages already imported.
 */
final class ImportFailedException extends \RuntimeException
{
    /**
     * @param array<string, mixed> $report partial report, see {@see JobImporter::import()}
     */
    public function __construct(
        string $message,
        private readonly array $report,
        private readonly bool $unrecoverable,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * @return array<string, mixed>
     */
    public function getReport(): array
    {
        return $this->report;
    }

    public function isUnrecoverable(): bool
    {
        return $this->unrecoverable;
    }
}
