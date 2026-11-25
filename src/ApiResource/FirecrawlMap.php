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
use Dmstr\Firecrawl\State\Passthru\MapProcessor;

/**
 * `POST /api/firecrawl/map`: Doctrine-less pass-through of a Firecrawl
 * map request, served by {@see MapProcessor} through
 * {@see \Dmstr\Firecrawl\Passthru\PassthruService}. The answer is the
 * decoded Firecrawl payload as a JsonResponse (HTTP 200); nothing is
 * persisted. Input schema: `config/schemas/firecrawl-map-input.json`.
 */
#[ApiResource(
    shortName: 'FirecrawlMap',
    operations: [
        new Post(
            uriTemplate: '/firecrawl/map',
            description: 'Asks the Firecrawl instance for the URLs of the given site and returns the list directly. Nothing is stored.',
            status: 200,
            formats: Operation::FORMATS,
            openapi: new OpenApiOperation(
                tags: [OpenApiTag::FIRECRAWL],
                summary: 'Maps the URLs of a site through Firecrawl (synchronous pass-through).',
                description: 'Forwards the body to `POST /v2/map` of a Firecrawl instance and returns its answer verbatim (e.g. `success` and `links`).'.Operation::DOC_COMMON,
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
            processor: MapProcessor::class,
        ),
    ],
    description: <<<'MD'
        Lists the URLs of a website through a Firecrawl instance, without scraping the pages.

        Use it to see which pages a crawl would find before you start one. Nothing is stored here.

        Related: the Firecrawl instance is chosen among the Firecrawl connections in **ApiConfiguration**; without a choice the single active one is used. Crawls are started under **FirecrawlJob** or **FirecrawlCrawl**.
        MD,
    openapi: new OpenApiOperation(tags: [OpenApiTag::FIRECRAWL]),
)]
final class FirecrawlMap
{
    public const string OPERATION = 'firecrawl_map';
}
