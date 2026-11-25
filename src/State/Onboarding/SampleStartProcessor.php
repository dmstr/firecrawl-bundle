<?php

// file generated with AI assistance: Claude Code - 2026-10-14 00:50:00 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\State\Onboarding;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use Dmstr\Firecrawl\ApiResource\CrawlOnboarding;
use Dmstr\Firecrawl\ApiResource\Onboarding\SampleStartInput;
use Dmstr\Firecrawl\ApiResource\Onboarding\SampleStartResult;
use Dmstr\Firecrawl\Client\FirecrawlClientInterface;
use Dmstr\Firecrawl\Onboarding\OnboardingClient;
use Dmstr\Firecrawl\Onboarding\OnboardingFailure;
use Dmstr\Firecrawl\Onboarding\SamplePageFetcher;

/**
 * MCP tool `crawl_sample_start`: starts the Firecrawl job of the sample
 * pages ({@see SamplePageFetcher::start()}) and returns its id at once.
 *
 * @implements ProcessorInterface<SampleStartInput, SampleStartResult>
 */
final readonly class SampleStartProcessor implements ProcessorInterface
{
    public function __construct(
        private OnboardingClient $client,
        private SamplePageFetcher $fetcher,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): SampleStartResult
    {
        if (!$data instanceof SampleStartInput) {
            throw new \InvalidArgumentException('Expected SampleStartInput payload');
        }

        // Validated before (validate: true): urls a list of 1-5 http(s) URLs.
        /** @var list<string> $urls */
        $urls = array_values(array_unique($data->urls));
        $limit = \is_int($data->limit) ? $data->limit : SamplePageFetcher::DEFAULT_LIMIT;

        try {
            $started = $this->client->run(
                CrawlOnboarding::TOOL_SAMPLE_START,
                \is_string($data->connection) ? $data->connection : null,
                $urls,
                fn (FirecrawlClientInterface $client): array => $this->fetcher->start($client, $urls, $limit),
            );
        } catch (OnboardingFailure $e) {
            return new SampleStartResult('failed', error: $e->getMessage());
        }

        return new SampleStartResult('started', $started['jobId'], $started['kind']);
    }
}
