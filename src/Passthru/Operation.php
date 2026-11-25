<?php

// file generated with AI assistance: Claude Code - 2026-10-03 00:09:10 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\Passthru;

use Dmstr\Firecrawl\ApiResource\FirecrawlBatchScrape;
use Dmstr\Firecrawl\ApiResource\FirecrawlCrawl;
use Dmstr\Firecrawl\ApiResource\FirecrawlMap;
use Dmstr\Firecrawl\ApiResource\FirecrawlScrape;
use Dmstr\Firecrawl\Client\FirecrawlClientInterface;

/**
 * The four synchronous pass-through operations. The case value is the API
 * Platform operation name, which also selects the input schema
 * (`config/schemas/<name-with-dashes>-input.json`). Each case knows its
 * client method ({@see call()}), the remote path, its resource class, its
 * console command and whether the body carries one `url` or a `urls` list,
 * so processors and commands stay thin.
 */
enum Operation: string
{
    case Scrape = FirecrawlScrape::OPERATION;
    case Map = FirecrawlMap::OPERATION;
    case Crawl = FirecrawlCrawl::OPERATION;
    case BatchScrape = FirecrawlBatchScrape::OPERATION;

    /**
     * Formats of the four operations. The app-wide API Platform formats are
     * `jsonld` only, which would make content negotiation refuse
     * `Accept: application/json` with 406 before the role check; a
     * pass-through answers plain JSON, so both are declared. Errors are
     * rendered as problem documents under `json`, as hydra errors under
     * `jsonld`.
     */
    public const array FORMATS = [
        'json' => ['application/json'],
        'jsonld' => ['application/ld+json'],
    ];

    /** OpenAPI description shared by the four operations (after the per-operation sentence). */
    public const string DOC_COMMON = ' The body is validated against the input schema first (400 malformed JSON, 422 schema violation); options the schema does not list are forwarded unchanged.'
        .' The connection is chosen with `apiConfiguration` (IRI, UUID or name of an active `firecrawl` connection) in the body or as query parameter (the query parameter wins);'
        .' without it the single active `firecrawl` connection is used, and 422 is returned when there is none or several. `apiConfiguration` is consumed by the app and not forwarded.'
        .' The request is synchronous and capped by the connection timeout (at most 55 s, below the 60 s limit of the php-fpm pool); a Firecrawl failure is answered with 502.'
        .' Nothing is persisted. Requires the permission `firecrawl:passthru:execute`.';

    public const string DOC_CONNECTION_PARAMETER = 'Firecrawl connection to use (IRI, full or partial UUID, or name of an active `firecrawl` connection); wins over `apiConfiguration` in the body. Omit when exactly one active `firecrawl` connection exists.';

    public const string DOC_RESPONSE_200 = 'The decoded Firecrawl answer, verbatim.';

    public const string DOC_RESPONSE_502 = 'Firecrawl failed or answered with a non-JSON body; the detail carries the remote status and a body excerpt.';

    /**
     * @throws \LogicException for a name that is not a pass-through operation
     */
    public static function fromName(string $operationName): self
    {
        return self::tryFrom($operationName)
            ?? throw new \LogicException(\sprintf('"%s" is not a Firecrawl pass-through operation.', $operationName));
    }

    /**
     * Short name used in messages and log lines (`scrape`, `batch_scrape`).
     */
    public function label(): string
    {
        return substr($this->value, \strlen('firecrawl_'));
    }

    /**
     * Firecrawl API path the client method requests.
     */
    public function remotePath(): string
    {
        return match ($this) {
            self::Scrape => '/v2/scrape',
            self::Map => '/v2/map',
            self::Crawl => '/v2/crawl',
            self::BatchScrape => '/v2/batch/scrape',
        };
    }

    /**
     * @return class-string
     */
    public function resourceClass(): string
    {
        return match ($this) {
            self::Scrape => FirecrawlScrape::class,
            self::Map => FirecrawlMap::class,
            self::Crawl => FirecrawlCrawl::class,
            self::BatchScrape => FirecrawlBatchScrape::class,
        };
    }

    /**
     * Route path below `/api`.
     */
    public function uriTemplate(): string
    {
        return '/firecrawl/'.$this->label();
    }

    public function commandName(): string
    {
        return 'firecrawl:'.str_replace('_', '-', $this->label());
    }

    /**
     * Body key of the target: `urls` (list) for batch scrape, else `url`.
     */
    public function urlKey(): string
    {
        return self::BatchScrape === $this ? 'urls' : 'url';
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed> the decoded Firecrawl answer
     */
    public function call(FirecrawlClientInterface $client, array $body): array
    {
        return match ($this) {
            self::Scrape => $client->scrape($body),
            self::Map => $client->map($body),
            self::Crawl => $client->crawl($body),
            self::BatchScrape => $client->batchScrape($body),
        };
    }

    /**
     * Target URL(s) of a validated body, for the audit log.
     *
     * @param array<string, mixed> $body
     *
     * @return list<string>
     */
    public function targetUrls(array $body): array
    {
        $value = $body[$this->urlKey()] ?? [];
        $urls = \is_array($value) ? $value : [$value];

        return array_values(array_map(static fn (mixed $url): string => \is_scalar($url) ? (string) $url : '', $urls));
    }
}
