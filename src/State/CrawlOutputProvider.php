<?php

// file generated with AI assistance: Claude Code - 2026-11-06 14:00:00 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\State;

use ApiPlatform\Metadata\CollectionOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use Dmstr\Firecrawl\ApiResource\CrawlUrlList;
use Dmstr\Firecrawl\Crawl\CrawlOutput;

/**
 * Serves {@see CrawlUrlList} from the files of the latest discover
 * ({@see CrawlOutput}): one entry per profile with the counts, a single entry
 * with the whole file.
 *
 * @implements ProviderInterface<CrawlUrlList>
 */
final class CrawlOutputProvider implements ProviderInterface
{
    public function __construct(
        private readonly CrawlOutput $output,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        return $operation instanceof CollectionOperationInterface
            ? array_values(array_filter(array_map(fn (string $profile): ?CrawlUrlList => $this->output->urlList($profile), $this->output->profiles())))
            : $this->output->urlList((string) ($uriVariables['profile'] ?? ''), true);
    }
}
