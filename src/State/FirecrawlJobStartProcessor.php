<?php

// file generated with AI assistance: Claude Code - 2026-10-03 00:10:23 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use Dmstr\Firecrawl\Connection\FirecrawlConnectionResolver;
use Dmstr\Firecrawl\Entity\Refs\FirecrawlJob;
use Dmstr\Firecrawl\Jobs\JobStarter;
use Symfony\Component\HttpFoundation\Request;

/**
 * `POST /refs/firecrawl_jobs`: validates the raw body against
 * `FirecrawlJob/start.input.json` (400 malformed JSON, 422 schema
 * violation), resolves the optional `apiConfiguration` (422 for unknown,
 * inactive, ambiguous or non-`firecrawl` connections; without it the single
 * active connection) and starts the job through {@see JobStarter}; a
 * `batch_scrape` takes its URLs from `urls`, the other types from `url`
 * (the schema requires the matching one). A failed
 * remote call is answered with 201 and the row in status `failed`.
 *
 * @implements ProcessorInterface<null, FirecrawlJob>
 */
final class FirecrawlJobStartProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly InputBodyValidator $inputBodyValidator,
        private readonly FirecrawlConnectionResolver $connectionResolver,
        private readonly JobStarter $jobStarter,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): FirecrawlJob
    {
        $request = $context['request'] ?? null;
        $body = $request instanceof Request ? $request->getContent() : '';

        $input = $this->inputBodyValidator->validate(FirecrawlJob::OPERATION_START, $body);

        $connection = $this->connectionResolver->resolve(isset($input['apiConfiguration']) ? (string) $input['apiConfiguration'] : null);

        /** @var array<string, mixed>|null $options */
        $options = $input['options'] ?? null;

        /** @var list<string> $urls */
        $urls = \is_array($input['urls'] ?? null) ? array_values(array_map('strval', $input['urls'])) : [];

        return $this->jobStarter->start(
            (string) ($input['type'] ?? FirecrawlJob::TYPE_CRAWL),
            (string) ($input['url'] ?? ''),
            $options,
            $connection,
            $urls,
        );
    }
}
