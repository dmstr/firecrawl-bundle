<?php

// file generated with AI assistance: Claude Code - 2026-10-13 21:47:18 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\ApiResource\Onboarding;

use ApiPlatform\Metadata\ApiProperty;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Arguments of the MCP tool `crawl_map_site`.
 */
final class SiteMapInput
{
    public const int DEFAULT_LIMIT = 2000;

    public const int MAX_LIMIT = 5000;

    #[Assert\NotBlank]
    #[Assert\Type('string')]
    #[Assert\Url(protocols: ['http', 'https'], requireTld: true)]
    #[ApiProperty(
        description: 'Start URL of the site or of a section (absolute http(s) URL).',
        required: true,
        schema: ['type' => 'string', 'format' => 'uri'],
    )]
    public mixed $url = null;

    #[Assert\Type('integer')]
    #[Assert\Range(min: 1, max: self::MAX_LIMIT)]
    #[ApiProperty(
        description: 'Maximum number of URLs to list (default 2000, at most 5000).',
        schema: ['type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_LIMIT, 'default' => self::DEFAULT_LIMIT],
    )]
    public mixed $limit = self::DEFAULT_LIMIT;

    #[Assert\Type('string')]
    #[ApiProperty(
        description: 'Firecrawl connection by name; without it the single active Firecrawl connection is used.',
        schema: ['type' => ['string', 'null']],
    )]
    public mixed $connection = null;
}
