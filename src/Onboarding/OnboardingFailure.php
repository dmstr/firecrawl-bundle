<?php

// file generated with AI assistance: Claude Code - 2026-10-14 00:20:00 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\Onboarding;

/**
 * A Firecrawl call of an onboarding tool failed; the message names the
 * tool and the HTTP status, never the remote body, which may carry page
 * content.
 */
final class OnboardingFailure extends \RuntimeException
{
    public function __construct(string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
