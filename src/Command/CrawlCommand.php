<?php

// file generated with AI assistance: Claude Code - 2026-10-03 00:09:10 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\Command;

use Dmstr\Firecrawl\Passthru\Operation;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Console twin of `POST /api/firecrawl/crawl`, see {@see AbstractPassthruCommand}.
 */
#[AsCommand(name: 'firecrawl:crawl', description: 'Start a Firecrawl crawl and print the answer (crawl id and status URL) as JSON')]
final class CrawlCommand extends AbstractPassthruCommand
{
    protected function operation(): Operation
    {
        return Operation::Crawl;
    }

    protected function bodyExample(): string
    {
        return '{"limit": 10, "includePaths": ["^/blog/.*$"], "scrapeOptions": {"formats": ["markdown"]}}';
    }
}
