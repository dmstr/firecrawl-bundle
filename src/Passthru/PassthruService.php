<?php

// file generated with AI assistance: Claude Code - 2026-10-03 00:09:10 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\Passthru;

use Dmstr\ApiConfiguration\Security\ConfigSecrets;
use Dmstr\Firecrawl\Client\FirecrawlApiException;
use Dmstr\Firecrawl\Client\FirecrawlClientFactoryInterface;
use Dmstr\Firecrawl\Client\FirecrawlProtocolException;
use Dmstr\Firecrawl\Connection\FirecrawlConnectionResolver;
use Dmstr\Firecrawl\State\InputBodyValidator;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Synchronous pass-through of one Firecrawl request, shared by the REST
 * processors (`POST /api/firecrawl/{scrape,map,crawl,batch_scrape}`) and
 * their console twins (`firecrawl:scrape` etc.), so validation, connection
 * choice, auditing and error mapping are identical on both paths:
 *
 *  1. the raw body is validated against the operation's input schema
 *     (400 malformed JSON, 422 schema violation);
 *  2. the connection reference is the explicit one (query parameter or
 *     `--connection`), else `apiConfiguration` from the body, else none
 *     (the single active firecrawl connection); `apiConfiguration` is
 *     removed from the body and never forwarded;
 *  3. the connection is resolved (422 when unusable) and a client created;
 *  4. the body is forwarded unchanged (unknown options included, empty JSON
 *     objects stay objects) and the decoded answer returned;
 *  5. one info line goes to the `firecrawl` log channel: operation,
 *     connection id and name, target URL(s), acting user (`cli` without a
 *     security token) and the outcome.
 *
 * Remote failures become HTTP 502:
 *  - FirecrawlProtocolException: `Firecrawl <op> failed: non-JSON answer from the instance (HTTP <status>).`
 *  - FirecrawlApiException with status: `Firecrawl <op> failed: HTTP <status>: <body excerpt>`
 *  - FirecrawlApiException without status (transport): `Firecrawl <op> failed: <message>`
 */
final class PassthruService
{
    public const string CONNECTION_KEY = 'apiConfiguration';

    /** Maximum length of the remote body excerpt in 502 messages. */
    public const int EXCERPT_LENGTH = 300;

    public function __construct(
        private readonly InputBodyValidator $inputBodyValidator,
        private readonly FirecrawlConnectionResolver $connectionResolver,
        private readonly FirecrawlClientFactoryInterface $clientFactory,
        private readonly ConfigSecrets $secrets,
        private readonly LoggerInterface $firecrawlLogger,
        private readonly ?Security $security = null,
    ) {
    }

    /**
     * @param string      $operationName       e.g. `firecrawl_scrape`, see {@see Operation}
     * @param string      $rawBody             JSON object; empty counts as `{}`
     * @param string|null $connectionReference IRI, (partial) UUID or name; wins over
     *                                         `apiConfiguration` in the body
     *
     * @return array<string, mixed> the decoded Firecrawl answer
     *
     * @throws BadRequestHttpException          on malformed JSON
     * @throws UnprocessableEntityHttpException on a schema violation or an unusable connection
     * @throws HttpException                    with status 502 when Firecrawl fails
     * @throws \LogicException                  for an unknown operation name
     */
    public function forward(string $operationName, string $rawBody, ?string $connectionReference = null): array
    {
        $operation = Operation::fromName($operationName);
        $this->inputBodyValidator->validate($operation->value, $rawBody);

        // Decoded again with objects, so `{}` deep inside the body is not
        // turned into `[]` on the way to Firecrawl.
        $body = self::toArray(json_decode('' === trim($rawBody) ? '{}' : $rawBody, false, 512, \JSON_THROW_ON_ERROR));

        $reference = trim((string) $connectionReference);
        if ('' === $reference && \is_string($body[self::CONNECTION_KEY] ?? null)) {
            $reference = trim($body[self::CONNECTION_KEY]);
        }
        unset($body[self::CONNECTION_KEY]);

        $connection = $this->connectionResolver->resolve('' === $reference ? null : $reference);
        $client = $this->clientFactory->create($this->secrets->decrypt($connection->getConfigJson()));

        $logContext = [
            'operation' => $operation->value,
            'connection_id' => (string) $connection->getId(),
            'connection_name' => $connection->getName(),
            'urls' => $operation->targetUrls($body),
            'user' => $this->security?->getUser()?->getUserIdentifier() ?? 'cli',
        ];

        try {
            $payload = $operation->call($client, $body);
        } catch (FirecrawlApiException $e) {
            $this->firecrawlLogger->info('Firecrawl {operation} via {connection_name} failed', $logContext + [
                'outcome' => 'error',
                'status' => $e->getStatusCode(),
            ]);

            throw new HttpException(502, self::failureMessage($operation, $e), $e);
        }

        $this->firecrawlLogger->info('Firecrawl {operation} via {connection_name}', $logContext + ['outcome' => 'ok']);

        return $payload;
    }

    private static function failureMessage(Operation $operation, FirecrawlApiException $e): string
    {
        $prefix = \sprintf('Firecrawl %s failed', $operation->label());
        $status = $e->getStatusCode();

        if ($e instanceof FirecrawlProtocolException) {
            return \sprintf('%s: non-JSON answer from the instance%s.', $prefix, null === $status ? '' : \sprintf(' (HTTP %d)', $status));
        }
        if (null === $status) {
            return \sprintf('%s: %s', $prefix, $e->getMessage());
        }

        $excerpt = trim(preg_replace('/\s+/', ' ', (string) $e->getResponseBody()) ?? '');
        if (mb_strlen($excerpt) > self::EXCERPT_LENGTH) {
            $excerpt = mb_substr($excerpt, 0, self::EXCERPT_LENGTH).'…';
        }

        return \sprintf('%s: HTTP %d: %s', $prefix, $status, '' === $excerpt ? '(empty body)' : $excerpt);
    }

    /**
     * Converts a decoded JSON object to an array, keeping empty objects as
     * \stdClass so they are encoded as `{}` again.
     *
     * @return array<string, mixed>
     */
    private static function toArray(object $object): array
    {
        $array = [];
        foreach (get_object_vars($object) as $key => $value) {
            $array[$key] = self::convert($value);
        }

        return $array;
    }

    private static function convert(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            return [] === get_object_vars($value) ? $value : self::toArray($value);
        }
        if (\is_array($value)) {
            return array_map(self::convert(...), $value);
        }

        return $value;
    }
}
