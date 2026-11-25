<?php

// file generated with AI assistance: Claude Code - 2026-10-13 21:47:18 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\ApiResource\Onboarding;

use ApiPlatform\Metadata\ApiProperty;

/**
 * Result of the MCP tool `crawl_sample_analyze`. No HTML is returned:
 * only the analysis, so the model never reads the raw pages.
 */
final readonly class BoilerplateResult
{
    public function __construct(
        #[ApiProperty(description: 'completed, pending (call again) or failed (see error).')]
        public string $status,
        #[ApiProperty(description: 'Id of the Firecrawl job.')]
        public string $jobId,
        #[ApiProperty(description: 'Pages the job has to fetch (null when unknown).')]
        public ?int $total = null,
        #[ApiProperty(description: 'Pages fetched so far (null when unknown).')]
        public ?int $completed = null,
        /** @var list<array{url: string, statusCode: int|null, title: string|null, error: string|null}> */
        #[ApiProperty(description: 'The scraped sample pages with url, statusCode, title and error.')]
        public array $pages = [],
        /** @var list<array<string, mixed>> */
        #[ApiProperty(description: 'Boilerplate candidates with selector, category, tag, pages, identicalText, sample and preselected.')]
        public array $candidates = [],
        /** @var list<string> */
        #[ApiProperty(description: 'Tags that are always removed.')]
        public array $alwaysExcluded = [],
        /** @var array<string, mixed>|null */
        #[ApiProperty(description: 'The multiple-choice form {id, schema, value} to pass to the user unchanged; null unless completed.')]
        public ?array $form = null,
        #[ApiProperty(description: 'Why the analysis failed; null unless status is failed.')]
        public ?string $error = null,
    ) {
    }
}
// - revised 2026-10-14
