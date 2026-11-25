<?php

// file generated with AI assistance: Claude Code - 2026-10-13 21:47:18 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\State\Onboarding;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use Dmstr\Firecrawl\ApiResource\CrawlOnboarding;
use Dmstr\Firecrawl\ApiResource\Onboarding\BoilerplateResult;
use Dmstr\Firecrawl\ApiResource\Onboarding\SampleAnalyzeInput;
use Dmstr\Firecrawl\Client\FirecrawlClientInterface;
use Dmstr\Firecrawl\Onboarding\BoilerplateAnalyzer;
use Dmstr\Firecrawl\Onboarding\BoilerplateForm;
use Dmstr\Firecrawl\Onboarding\FormStore;
use Dmstr\Firecrawl\Onboarding\OnboardingClient;
use Dmstr\Firecrawl\Onboarding\OnboardingFailure;
use Dmstr\Firecrawl\Onboarding\SamplePageFetcher;

/**
 * MCP tool `crawl_sample_analyze`: waits for the sample job
 * ({@see SamplePageFetcher::collect()}), then raw HTML →
 * {@see BoilerplateAnalyzer} → {@see BoilerplateForm}. Pages that failed or
 * came back without HTML are listed but not analysed; the comparison runs
 * over the others. The form is also kept in {@see FormStore} for the
 * answer tool.
 *
 * @implements ProcessorInterface<SampleAnalyzeInput, BoilerplateResult>
 */
final readonly class SampleAnalyzeProcessor implements ProcessorInterface
{
    public function __construct(
        private OnboardingClient $client,
        private SamplePageFetcher $fetcher,
        private BoilerplateAnalyzer $analyzer,
        private FormStore $forms,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): BoilerplateResult
    {
        if (!$data instanceof SampleAnalyzeInput) {
            throw new \InvalidArgumentException('Expected SampleAnalyzeInput payload');
        }

        // Validated before (validate: true): jobId a plain id, kind crawl or batch.
        $jobId = (string) $data->jobId;
        $kind = (string) $data->kind;

        try {
            $fetched = $this->client->run(
                CrawlOnboarding::TOOL_SAMPLE_ANALYZE,
                \is_string($data->connection) ? $data->connection : null,
                [],
                fn (FirecrawlClientInterface $client): array => $this->fetcher->collect($client, $kind, $jobId),
            );
        } catch (OnboardingFailure $e) {
            return new BoilerplateResult('failed', $jobId, error: $e->getMessage());
        }

        $pages = array_map(static fn (array $page): array => [
            'url' => $page['url'],
            'statusCode' => $page['statusCode'],
            'title' => $page['title'],
            'error' => $page['error'] ?? ('' === $page['html'] ? 'no HTML returned' : null),
        ], $fetched['pages']);

        if ('completed' !== $fetched['status']) {
            return new BoilerplateResult($fetched['status'], $jobId, $fetched['total'], $fetched['completed'], $pages, error: 'failed' === $fetched['status'] ? 'Firecrawl reported the job as failed.' : null);
        }

        $usable = array_values(array_filter(
            $fetched['pages'],
            static fn (array $page): bool => '' !== $page['html'] && null === $page['error'] && ($page['statusCode'] ?? 200) < 400,
        ));
        $analysis = $this->analyzer->analyze(array_map(
            static fn (array $page): array => ['url' => $page['url'], 'html' => $page['html']],
            $usable,
        ));

        $form = BoilerplateForm::build($analysis['candidates'], $analysis['pageCount']);
        $this->forms->save($jobId, $form);

        return new BoilerplateResult(
            'completed',
            $jobId,
            $fetched['total'],
            $fetched['completed'],
            $pages,
            $analysis['candidates'],
            $analysis['alwaysExcluded'],
            $form,
        );
    }
}
// - revised 2026-10-14
