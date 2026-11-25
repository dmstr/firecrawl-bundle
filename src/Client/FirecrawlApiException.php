<?php

// file generated with AI assistance: Claude Code - 2026-10-02 23:51:30 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\Client;

/**
 * Base exception of every {@see FirecrawlClientInterface} call:
 *  - HTTP status >= 400: status code and up to 500 bytes of the body;
 *  - transport failure (DNS, connect, TLS, timeout): status null, the
 *    Symfony TransportExceptionInterface as previous;
 *  - {@see FirecrawlProtocolException}: 2xx without a usable JSON object.
 */
class FirecrawlApiException extends \RuntimeException
{
    public const int MAX_BODY_BYTES = 500;

    private readonly ?string $responseBody;

    public function __construct(
        string $message,
        private readonly ?int $statusCode = null,
        ?string $responseBody = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode ?? 0, $previous);

        $this->responseBody = null === $responseBody ? null : substr($responseBody, 0, self::MAX_BODY_BYTES);
    }

    /**
     * HTTP status of the response; null for transport failures.
     */
    public function getStatusCode(): ?int
    {
        return $this->statusCode;
    }

    /**
     * Up to 500 bytes of the response body; null when there was no response.
     */
    public function getResponseBody(): ?string
    {
        return $this->responseBody;
    }
}
