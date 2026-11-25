<?php

// file generated with AI assistance: Claude Code - 2026-11-06 14:00:00 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use Dmstr\Firecrawl\OpenApi\OpenApiTag;
use Dmstr\Firecrawl\Security\FirecrawlPermissions;
use Dmstr\Firecrawl\State\CrawlOutputProvider;

/**
 * The URL list of the latest `app:crawl:discover` of a profile
 * (`urls.json`), one entry per profile, read from the file, not stored. The
 * list shows the counts; the single entry carries the whole file.
 */
#[ApiResource(
    operations: [
        new GetCollection(),
        new Get(),
    ],
    routePrefix: '/admin',
    description: <<<'MD'
        The URL list the latest discover of a crawl profile wrote: the pages to crawl, the document files to convert and the broken links with the page that links to them. Open one to see the whole list.

        Only the latest result exists; the next discover replaces it. Start it with `app:crawl:discover <profile>`.

        Related: **CrawlProfile**.
        MD,
    security: "is_granted('".FirecrawlPermissions::PROFILE_MANAGE."')",
    provider: CrawlOutputProvider::class,
    openapi: new OpenApiOperation(tags: [OpenApiTag::FIRECRAWL]),
)]
final class CrawlUrlList
{
    /** Set on the single entry only. */
    #[ApiProperty(description: 'The whole urls.json; only on the single entry', openapiContext: ['type' => 'string', 'x-format' => 'ace', 'x-purifyData' => false, 'x-ace' => ['mode' => 'json', 'options' => ['minLines' => 10, 'maxLines' => 200]], 'x-grid' => ['columnsMd' => 12, 'newRow' => true]])]
    public ?string $urls = null;

    public function __construct(
        #[ApiProperty(identifier: true, description: 'Name of the crawl profile')]
        public readonly string $profile,
        #[ApiProperty(description: 'When the latest discover wrote the list')]
        public readonly \DateTimeImmutable $discoveredAt,
        #[ApiProperty(description: 'Number of pages to crawl')]
        public readonly int $pages,
        #[ApiProperty(description: 'Number of document files to convert')]
        public readonly int $files,
        #[ApiProperty(description: 'Number of broken links')]
        public readonly int $broken,
    ) {
    }
}
