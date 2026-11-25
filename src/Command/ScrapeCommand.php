<?php

// file generated with AI assistance: Claude Code - 2026-10-03 00:09:10 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\Command;

use Dmstr\Firecrawl\Passthru\Operation;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Console twin of `POST /api/firecrawl/scrape`, see {@see AbstractPassthruCommand}.
 */
#[AsCommand(name: 'firecrawl:scrape', description: 'Scrape one page through Firecrawl and print the answer as JSON')]
final class ScrapeCommand extends AbstractPassthruCommand
{
    protected function operation(): Operation
    {
        return Operation::Scrape;
    }

    protected function bodyExample(): string
    {
        return '{"formats": ["markdown", "links"], "onlyMainContent": true}';
    }
}
