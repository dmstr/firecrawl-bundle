<?php

// file generated with AI assistance: Claude Code - 2026-10-13 21:47:18 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\ApiResource\Onboarding;

use ApiPlatform\Metadata\ApiProperty;
use Dmstr\Firecrawl\Onboarding\SamplePageFetcher;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Arguments of the MCP tool `crawl_sample_start`.
 *
 * API Platform maps tool arguments without type coercion, so the
 * properties are untyped and the constraints check the types.
 */
final class SampleStartInput
{
    public const int MAX_URLS = 5;

    #[Assert\NotNull]
    #[Assert\Type('array')]
    #[Assert\Count(min: 1, max: self::MAX_URLS)]
    #[Assert\All([
        new Assert\NotBlank(),
        new Assert\Type('string'),
        new Assert\Url(protocols: ['http', 'https'], requireTld: true),
    ])]
    #[ApiProperty(
        description: 'One to five sample pages of the same site (absolute http(s) URLs): one URL starts a crawl of a few pages from it, several URLs a scrape of exactly these pages.',
        required: true,
        schema: ['type' => 'array', 'minItems' => 1, 'maxItems' => self::MAX_URLS, 'items' => ['type' => 'string', 'format' => 'uri']],
    )]
    public mixed $urls = null;

    #[Assert\Type('integer')]
    #[Assert\Range(min: 2, max: SamplePageFetcher::MAX_LIMIT)]
    #[ApiProperty(
        description: 'Page limit of the crawl when only one URL is given (default 10, at most 25); ignored for several URLs.',
        schema: ['type' => 'integer', 'minimum' => 2, 'maximum' => SamplePageFetcher::MAX_LIMIT, 'default' => SamplePageFetcher::DEFAULT_LIMIT],
    )]
    public mixed $limit = SamplePageFetcher::DEFAULT_LIMIT;

    #[Assert\Type('string')]
    #[ApiProperty(
        description: 'Firecrawl connection by name; without it the single active Firecrawl connection is used.',
        schema: ['type' => ['string', 'null']],
    )]
    public mixed $connection = null;
}
// - revised 2026-10-14
