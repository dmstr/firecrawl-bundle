<?php

// file generated with AI assistance: Claude Code - 2026-10-14 00:50:00 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\ApiResource\Onboarding;

use ApiPlatform\Metadata\ApiProperty;

/**
 * Result of the MCP tool `crawl_sample_start`.
 */
final readonly class SampleStartResult
{
    public function __construct(
        #[ApiProperty(description: 'started, or failed (see error).')]
        public string $status,
        #[ApiProperty(description: 'Id of the Firecrawl job; pass it to crawl_sample_analyze.')]
        public ?string $jobId = null,
        #[ApiProperty(description: 'crawl (one start URL) or batch (several); pass it to crawl_sample_analyze.')]
        public ?string $kind = null,
        #[ApiProperty(description: 'Why the job could not be started; null when started.')]
        public ?string $error = null,
    ) {
    }
}
