<?php

// file generated with AI assistance: Claude Code - 2026-10-03 00:09:10 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use ApiPlatform\OpenApi\Model\Parameter;
use ApiPlatform\OpenApi\Model\Response as OpenApiResponse;
use Dmstr\Firecrawl\OpenApi\OpenApiTag;
use Dmstr\Firecrawl\Passthru\Operation;
use Dmstr\Firecrawl\Security\FirecrawlPermissions;
use Dmstr\Firecrawl\State\Passthru\ScrapeProcessor;

/**
 * `POST /api/firecrawl/scrape`: Doctrine-less pass-through of a Firecrawl
 * scrape request, served by {@see ScrapeProcessor} through
 * {@see \Dmstr\Firecrawl\Passthru\PassthruService}. The answer is the
 * decoded Firecrawl payload as a JsonResponse (HTTP 200); nothing is
 * persisted. Input schema: `config/schemas/firecrawl-scrape-input.json`.
 */
#[ApiResource(
    shortName: 'FirecrawlScrape',
    operations: [
        new Post(
            uriTemplate: '/firecrawl/scrape',
            description: 'Scrapes the given page on the Firecrawl instance and returns the result directly. Nothing is stored; to keep the job and import it into a knowledge collection, start a scrape under **FirecrawlJob**.',
            status: 200,
            formats: Operation::FORMATS,
            openapi: new OpenApiOperation(
                tags: [OpenApiTag::FIRECRAWL],
                summary: 'Scrapes one page through Firecrawl (synchronous pass-through).',
                description: 'Forwards the body to `POST /v2/scrape` of a Firecrawl instance and returns its answer verbatim (e.g. `success` and `data` with `markdown`, `html`, `links`, `metadata`).'.Operation::DOC_COMMON,
                parameters: [
                    new Parameter('apiConfiguration', 'query', Operation::DOC_CONNECTION_PARAMETER, schema: ['type' => 'string', 'minLength' => 1]),
                ],
                responses: [
                    '200' => new OpenApiResponse(
                        description: Operation::DOC_RESPONSE_200,
                        content: new \ArrayObject(['application/json' => ['schema' => ['type' => 'object', 'additionalProperties' => true]]]),
                    ),
                    '502' => new OpenApiResponse(description: Operation::DOC_RESPONSE_502),
                ],
            ),
            security: "is_granted('".FirecrawlPermissions::PASSTHRU_EXECUTE."')",
            input: false,
            output: false,
            read: false,
            deserialize: false,
            validate: false,
            name: self::OPERATION,
            processor: ScrapeProcessor::class,
        ),
    ],
    description: <<<'MD'
        Scrapes a single web page with a Firecrawl instance and shows the result right away, e.g. the page as Markdown, its links and metadata.

        Use it to try out a page or scrape options. Nothing is stored here.

        Related: the Firecrawl instance is chosen among the Firecrawl connections in **ApiConfiguration**; without a choice the single active one is used. To keep a record of a job, start it under **FirecrawlJob** instead.
        MD,
    openapi: new OpenApiOperation(tags: [OpenApiTag::FIRECRAWL]),
)]
final class FirecrawlScrape
{
    public const string OPERATION = 'firecrawl_scrape';
}
