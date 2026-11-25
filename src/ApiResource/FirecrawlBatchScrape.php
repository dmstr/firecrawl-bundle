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
use Dmstr\Firecrawl\State\Passthru\BatchScrapeProcessor;

/**
 * `POST /api/firecrawl/batch_scrape`: Doctrine-less pass-through of a Firecrawl
 * batch scrape request, served by {@see BatchScrapeProcessor} through
 * {@see \Dmstr\Firecrawl\Passthru\PassthruService}. The answer is the
 * decoded Firecrawl payload as a JsonResponse (HTTP 200); nothing is
 * persisted. Input schema: `config/schemas/firecrawl-batch-scrape-input.json`.
 */
#[ApiResource(
    shortName: 'FirecrawlBatchScrape',
    operations: [
        new Post(
            uriTemplate: '/firecrawl/batch_scrape',
            description: 'Starts the batch scrape on the Firecrawl instance and returns its id and status link. The batch is not stored or tracked; to follow it and import its pages, start it under **FirecrawlJob**.',
            status: 200,
            formats: Operation::FORMATS,
            openapi: new OpenApiOperation(
                tags: [OpenApiTag::FIRECRAWL],
                summary: 'Starts a Firecrawl batch scrape (synchronous pass-through, nothing is stored).',
                description: 'Forwards the body to `POST /v2/batch/scrape` of a Firecrawl instance and returns its answer verbatim (`success`, the batch `id` and its status `url`). The batch runs on the instance; this operation neither stores nor tracks it.'.Operation::DOC_COMMON,
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
            processor: BatchScrapeProcessor::class,
        ),
    ],
    description: <<<'MD'
        Starts a batch scrape on a Firecrawl instance: a list of URLs is scraped in one go.

        The batch runs on the instance; you get back its id and status link, but it is neither stored nor tracked here.

        Related: the Firecrawl instance is chosen among the Firecrawl connections in **ApiConfiguration**; without a choice the single active one is used. To keep a record of a job, start it under **FirecrawlJob** instead.
        MD,
    openapi: new OpenApiOperation(tags: [OpenApiTag::FIRECRAWL]),
)]
final class FirecrawlBatchScrape
{
    public const string OPERATION = 'firecrawl_batch_scrape';
}
