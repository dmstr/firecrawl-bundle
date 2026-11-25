<?php

// file generated with AI assistance: Claude Code - 2026-10-03 00:09:10 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\State\Passthru;

use ApiPlatform\Metadata\Operation as ApiOperation;
use ApiPlatform\State\ProcessorInterface;
use Dmstr\Firecrawl\Passthru\Operation;
use Dmstr\Firecrawl\Passthru\PassthruService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Shared processor of the four pass-through resources: hands the raw
 * request body and the optional `apiConfiguration` query parameter to
 * {@see PassthruService::forward()} and answers with the decoded Firecrawl
 * payload as a JsonResponse (HTTP 200). API Platform's serialize and respond
 * processors pass a Response through unchanged.
 *
 * A 502 of the service is answered here as an `application/problem+json`
 * document (`type`, `title`, `status`, `detail`) instead of being thrown:
 * API Platform's ErrorProvider replaces the detail of every 5xx with
 * "Internal Server Error" when the kernel is not in debug mode, and the
 * remote status and body excerpt are what the caller needs. 400 and 422 are
 * thrown and rendered by API Platform as usual.
 *
 * @implements ProcessorInterface<null, JsonResponse>
 */
abstract class AbstractPassthruProcessor implements ProcessorInterface
{
    private const int JSON_OPTIONS = \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION;

    public function __construct(
        private readonly PassthruService $passthruService,
    ) {
    }

    abstract protected function operation(): Operation;

    public function process(mixed $data, ApiOperation $operation, array $uriVariables = [], array $context = []): JsonResponse
    {
        $request = $context['request'] ?? null;
        $body = '';
        $reference = '';
        if ($request instanceof Request) {
            $body = $request->getContent();
            $reference = trim($request->query->getString(PassthruService::CONNECTION_KEY));
        }

        try {
            $payload = $this->passthruService->forward($this->operation()->value, $body, '' === $reference ? null : $reference);
        } catch (HttpException $e) {
            if (Response::HTTP_BAD_GATEWAY !== $e->getStatusCode()) {
                throw $e;
            }

            return $this->json([
                'type' => '/errors/502',
                'title' => 'An error occurred',
                'status' => Response::HTTP_BAD_GATEWAY,
                'detail' => $e->getMessage(),
            ], Response::HTTP_BAD_GATEWAY, ['Content-Type' => 'application/problem+json']);
        }

        // An empty answer is still a JSON object.
        return $this->json([] === $payload ? new \stdClass() : $payload);
    }

    /**
     * @param array<string, mixed>|\stdClass $data
     * @param array<string, string>          $headers
     */
    private function json(array|\stdClass $data, int $status = Response::HTTP_OK, array $headers = []): JsonResponse
    {
        $response = new JsonResponse(null, $status, $headers);
        $response->setEncodingOptions(self::JSON_OPTIONS);

        return $response->setData($data);
    }
}
