<?php

// file generated with AI assistance: Claude Code - 2026-10-03 07:30:27 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\Import;

/**
 * Unrecoverable failure while reading a Firecrawl result
 * ({@see ResultReader}): the remote job is not `completed`, its result has
 * expired or is unknown on the instance (HTTP 404, a 2xx body with
 * `success: false`, or `expiresAt` in the past), or a continuation link
 * does not belong to the job. Retrying cannot help; the job processor maps
 * it to an unrecoverable messenger failure.
 *
 * Transport failures and other HTTP errors are NOT wrapped: they stay
 * {@see \Dmstr\Firecrawl\Client\FirecrawlApiException} and may be retried.
 */
final class ResultReaderException extends \RuntimeException
{
}
