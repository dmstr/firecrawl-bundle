<?php

// file generated with AI assistance: Claude Code - 2026-10-03 00:09:10 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\Command;

use Dmstr\Firecrawl\Passthru\Operation;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Console twin of `POST /api/firecrawl/batch_scrape`, see {@see AbstractPassthruCommand}.
 */
#[AsCommand(name: 'firecrawl:batch-scrape', description: 'Start a Firecrawl batch scrape and print the answer (batch id and status URL) as JSON')]
final class BatchScrapeCommand extends AbstractPassthruCommand
{
    protected function operation(): Operation
    {
        return Operation::BatchScrape;
    }

    protected function bodyExample(): string
    {
        return '{"formats": ["markdown"], "ignoreInvalidURLs": true}';
    }
}
