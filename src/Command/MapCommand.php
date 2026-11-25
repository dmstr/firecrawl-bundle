<?php

// file generated with AI assistance: Claude Code - 2026-10-03 00:09:10 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\Command;

use Dmstr\Firecrawl\Passthru\Operation;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Console twin of `POST /api/firecrawl/map`, see {@see AbstractPassthruCommand}.
 */
#[AsCommand(name: 'firecrawl:map', description: 'Map the URLs of a site through Firecrawl and print the answer as JSON')]
final class MapCommand extends AbstractPassthruCommand
{
    protected function operation(): Operation
    {
        return Operation::Map;
    }

    protected function bodyExample(): string
    {
        return '{"search": "docs", "limit": 100}';
    }
}
