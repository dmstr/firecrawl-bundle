<?php

// file generated with AI assistance: Claude Code - 2026-10-03 00:09:10 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\State\Passthru;

use Dmstr\Firecrawl\Passthru\Operation;

/**
 * `POST /api/firecrawl/map`, see {@see AbstractPassthruProcessor}.
 */
final class MapProcessor extends AbstractPassthruProcessor
{
    protected function operation(): Operation
    {
        return Operation::Map;
    }
}
