<?php

// file generated with AI assistance: Claude Code - 2026-10-14 00:50:00 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\ApiResource\Onboarding;

use ApiPlatform\Metadata\ApiProperty;
use Dmstr\Firecrawl\Onboarding\SamplePageFetcher;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Arguments of the MCP tool `crawl_sample_analyze`.
 */
final class SampleAnalyzeInput
{
    public const array KINDS = [SamplePageFetcher::KIND_CRAWL, SamplePageFetcher::KIND_BATCH];

    #[Assert\NotBlank]
    #[Assert\Type('string')]
    #[Assert\Regex('/^[A-Za-z0-9_-]{1,100}$/')]
    #[ApiProperty(
        description: 'The jobId returned by crawl_sample_start.',
        required: true,
        schema: ['type' => 'string', 'minLength' => 1],
    )]
    public mixed $jobId = null;

    #[Assert\NotBlank]
    #[Assert\Choice(choices: self::KINDS)]
    #[ApiProperty(
        description: 'The kind returned by crawl_sample_start: crawl or batch.',
        required: true,
        schema: ['type' => 'string', 'enum' => self::KINDS],
    )]
    public mixed $kind = null;

    #[Assert\Type('string')]
    #[ApiProperty(
        description: 'Firecrawl connection by name; the same as for crawl_sample_start.',
        schema: ['type' => ['string', 'null']],
    )]
    public mixed $connection = null;
}
