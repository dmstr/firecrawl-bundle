<?php

// file generated with AI assistance: Claude Code - 2026-10-13 21:47:18 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\ApiResource\Onboarding;

use ApiPlatform\Metadata\ApiProperty;

/**
 * Result of the MCP tool `crawl_map_site`: aggregates of the URL list, not
 * the list itself, because a site's URL list does not fit a turn.
 */
final readonly class SiteMapResult
{
    public function __construct(
        #[ApiProperty(description: 'Number of URLs found.')]
        public int $total,
        #[ApiProperty(description: 'True when the limit was reached, so the site may have more URLs.')]
        public bool $limitReached,
        /** @var array<string, int> */
        #[ApiProperty(description: 'URL count per host.')]
        public array $hosts = [],
        /** @var list<array{prefix: string, count: int}> */
        #[ApiProperty(description: 'Language-like first path segments (de, en-us, …) with their URL count.')]
        public array $languages = [],
        /** @var list<array{path: string, count: int}> */
        #[ApiProperty(description: 'The most frequent path prefixes of up to two segments with their URL count, as candidates for topics.')]
        public array $sections = [],
        /** @var list<string> */
        #[ApiProperty(description: 'A few URLs spread over the sections.')]
        public array $sampleUrls = [],
        #[ApiProperty(description: 'Why the map failed; null when it succeeded.')]
        public ?string $error = null,
    ) {
    }
}
