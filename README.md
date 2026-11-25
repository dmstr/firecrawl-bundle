<!-- file generated with AI assistance: Claude Code - 2026-10-02 23:51:30 UTC -->

# dmstr/firecrawl-bundle

Symfony bundle for self-hosted [Firecrawl](https://github.com/firecrawl/firecrawl) instances (REST API v2). It registers Firecrawl as the connection type `firecrawl` of dmstr/api-configuration-bundle: an instance is an `ApiConfiguration` row with `configJson.type = "firecrawl"`, managed under `/api/admin/api_configurations` like every other connection, with a health probe. On top of that the bundle provides:

- a Firecrawl v2 client behind `Dmstr\Firecrawl\Client\FirecrawlClientInterface`, created per connection by `FirecrawlClientFactoryInterface`;
- `FirecrawlConnectionResolver`, which turns an optional connection reference into an active `firecrawl` connection;
- `State\InputBodyValidator`, which validates raw request bodies against the input schemas of the bundle's operations;
- the idempotent seed command `firecrawl:connection:seed`;
- four synchronous pass-through resources under `/api/firecrawl` (scrape, map, crawl, batch scrape) with console twins;
- the job reference table `Refs\FirecrawlJob` under `/api/refs/firecrawl_jobs` (start, sync, scan of active crawls) with console twins;
- the import of a completed crawl or batch scrape into a knowledge collection, one document per page, through a port the application implements (`Import\PageImporterInterface`);
- the connection type `file-converter` (the service that turns document files into Markdown) with a health probe (`GET /health`) and the seed command `firecrawl:file-converter:seed`;
- crawl profiles under `/api/admin/crawl_profiles`: one website per profile, its Firecrawl connection and an encrypted login (document files go through the only active `file-converter` connection), crawled and imported with the actions Discover, Crawl and Import (background jobs, `POST …/{id}/discover|crawl|import`) or `app:crawl:discover`, `app:crawl:run` and `app:crawl:import <name>`; the `import` section names the collection and the chunk sizes; imported from the former YAML files with `firecrawl:crawl-profile:import <file>`;
- the URL list of the latest discover as the read-only resource `/api/admin/crawl_url_lists`: one entry per profile with the counts, the whole `urls.json` on the single entry, read from `var/crawl/<profile>/`, not stored;
- four read-only MCP tools for the crawl onboarding (`crawl_sample_start`, `crawl_sample_analyze`, `crawl_map_site`, `crawl_onboarding_reply`);
- a scriptable test seam (`Testing\FakeFirecrawlClientFactory`), so no test contacts an instance.

## Requirements

- PHP >= 8.5 with `ext-dom` (the boilerplate analysis uses `Dom\HTMLDocument`) and `ext-mbstring`
- Symfony 7.4 (FrameworkBundle, SecurityBundle, MonologBundle, Messenger), Doctrine ORM 3 with DBAL 3 and DoctrineMigrationsBundle
- PostgreSQL: the migrations create `UUID`, `JSON` and `TIMESTAMP WITH TIME ZONE` columns and use a PL/pgSQL block
- API Platform `^4.4.8`
- The dmstr bundles it builds on, installed as dependencies: [`dmstr/api-configuration-bundle`](https://github.com/dmstr/api-configuration-bundle) `^0.7` (connections), [`dmstr/openapi-json-schema-bundle`](https://github.com/dmstr/openapi-json-schema-bundle) `^0.5` (input schemas), [`dmstr/api-platform-utils-bundle`](https://github.com/dmstr/api-platform-utils-bundle) `^0.6` and [`dmstr/symfony-job-queue-bundle`](https://github.com/dmstr/symfony-job-queue-bundle) `^0.6` (background jobs on Messenger)
- An implementation of `Dmstr\Firecrawl\Import\PageImporterInterface`, see [Bundle and application](#bundle-and-application)
- A security voter for the bundle's permissions, see [Security](#security)
- Optional: [`dmstr/mcp-endpoint-bundle`](https://github.com/dmstr/mcp-endpoint-bundle) to serve the crawl onboarding MCP tools

## Installation

```bash
composer require dmstr/firecrawl-bundle
```

Register the bundle and the bundles it builds on in `config/bundles.php` (Symfony Flex adds most of them), the bundle after dmstr/api-configuration-bundle and dmstr/openapi-json-schema-bundle:

```php
return [
    Symfony\Bundle\FrameworkBundle\FrameworkBundle::class => ['all' => true],
    Symfony\Bundle\SecurityBundle\SecurityBundle::class => ['all' => true],
    Symfony\Bundle\MonologBundle\MonologBundle::class => ['all' => true],
    Doctrine\Bundle\DoctrineBundle\DoctrineBundle::class => ['all' => true],
    Doctrine\Bundle\MigrationsBundle\DoctrineMigrationsBundle::class => ['all' => true],
    ApiPlatform\Symfony\Bundle\ApiPlatformBundle::class => ['all' => true],
    Dmstr\OpenApiJsonSchema\OpenApiJsonSchemaBundle::class => ['all' => true],
    Dmstr\ApiPlatformUtils\ApiPlatformUtilsBundle::class => ['all' => true],
    Dmstr\ApiConfiguration\ApiConfigurationBundle::class => ['all' => true],
    Dmstr\SymfonyJobQueue\SymfonyJobQueueBundle::class => ['all' => true],
    Dmstr\Firecrawl\FirecrawlBundle::class => ['all' => true],
];
```

## Configuration

### Minimal configuration

The bundle ships no implementation of the import port, so the container does not compile until the host application aliases `Dmstr\Firecrawl\Import\PageImporterInterface` to its own service. This alias is mandatory:

```yaml
# config/services.yaml
services:
    Dmstr\Firecrawl\Import\PageImporterInterface: '@App\Import\FirecrawlPageImporter'
```

The Monolog channel `firecrawl` is prepended by the bundle; MonologBundle has to be registered, because services receive the logger `monolog.logger.firecrawl`. Route it like any other channel if needed:

```yaml
# config/packages/monolog.yaml
monolog:
    handlers:
        firecrawl:
            type: stream
            path: '%kernel.logs_dir%/firecrawl.log'
            channels: ['firecrawl']
```

Background jobs (scan, import, crawl profile actions) run on dmstr/symfony-job-queue-bundle, so a Messenger transport and a running worker are required as described there.

The bundles this one builds on expect a few things from the host application that they do not register themselves:

- the parameter `app.oauth.redirect_uri`, the OAuth callback URL of dmstr/api-configuration-bundle (its authorize provider, callback controller and redirect listener inject it);
- the encryption key `dmstr_api_platform_utils.credential_encryption.key` of dmstr/api-platform-utils-bundle (required, base64-encoded, generated with `sodium_crypto_secretbox_keygen()`), which encrypts connection secrets such as the Firecrawl `api_key` and crawl profile logins;
- a service `Dmstr\OpenApiJsonSchema\Service\OperationInputSchemaResolver`: dmstr/symfony-job-queue-bundle injects this class into its `JobQueueService`, and dmstr/openapi-json-schema-bundle does not register it. It is the application's own resolver; with no schema directory of its own, `$schemaBasePaths` may be empty, because the bundles register their schemas with resolvers of their own;
- the decorator `Dmstr\OpenApiJsonSchema\OpenApi\OperationInputSchemaDecorator` on `api_platform.openapi.factory`, which dmstr/openapi-json-schema-bundle ships unregistered. Without it the OpenAPI document has no request bodies for the bundle's operations; validation of request bodies works either way.

```yaml
# config/services.yaml
parameters:
    app.oauth.redirect_uri: 'https://app.example.org/api/oauth/callback'

services:
    Dmstr\OpenApiJsonSchema\Service\OperationInputSchemaResolver:
        arguments:
            $schemaBasePaths: []

    Dmstr\OpenApiJsonSchema\OpenApi\OperationInputSchemaDecorator:
        decorates: api_platform.openapi.factory
        arguments:
            $decorated: '@.inner'
```

```yaml
# config/packages/dmstr_api_platform_utils.yaml
dmstr_api_platform_utils:
    credential_encryption:
        key: '%env(CREDENTIAL_ENCRYPTION_KEY)%'
```

### Bundle options

Package configuration (`config/packages/dmstr_firecrawl.yaml`) is optional. The values (`base_url`, `api_key`) are the default input of the single connection of the seed command only, both default to empty; at runtime every client is built from a connection record. A seed file (see [Seed command](#seed-command)) needs no configuration:

```yaml
dmstr_firecrawl:
    seed:
        base_url: 'https://firecrawl.example.org'
```

### Security

Security (`config/packages/security.yaml`): the bundle's routes live below `/api/firecrawl`, `/api/refs/firecrawl_jobs`, `/api/admin/crawl_profiles` and `/api/admin/crawl_url_lists`. Put them behind the protected API firewall and require an authenticated user (`IS_AUTHENTICATED_FULLY`). Each operation then checks its own permission with `is_granted()`, decided by the permission voter of the host application. The bundle registers the permissions at the container tag `za7_core.permission`; which roles hold them is the application's map `za7_core.permissions`:

| Permission | Operations |
|---|---|
| `firecrawl:passthru:execute` | the pass-through operations `scrape`, `map`, `crawl`, `batch_scrape` under `/api/firecrawl` |
| `firecrawl:job:read` | `GET` item and collection of `/api/refs/firecrawl_jobs` |
| `firecrawl:job:write` | `start`, `sync`, `scan` and `import` of the job reference table |
| `firecrawl:onboarding:use` | the four crawl onboarding tools |
| `firecrawl:profile:manage` | every operation of `/api/admin/crawl_profiles` (a profile holds a site's login) |

The application lists the roles of each permission under its key in `za7_core.permissions`; the bundle knows no role names. A permission without a map entry is denied for everyone.

### Prepended configuration

The bundle prepends its Doctrine mapping (`src/Entity`), its API Platform mapping paths (`src/Entity`, `src/ApiResource`), its migrations path (`migrations/`, namespace `Dmstr\Firecrawl\Migrations`), the validation mapping `config/validator/ApiConfiguration.xml` and the Monolog channel `firecrawl`; the host application configures none of them.

## Connection schema

The `firecrawl` branch of `ApiConfiguration.configJson` is [`schema.json`](schema.json) (draft-07):

| Field | Type | Required | Meaning |
|---|---|---|---|
| `firecrawl` | `true` | yes | discriminator flag (read-only) |
| `type` | `"firecrawl"` | yes | connection type (read-only) |
| `endpoint_type` | `"rest"` | yes | endpoint type (read-only) |
| `base_url` | URI, `^https?://` | yes | base URL of the instance; the client appends `/v2/...` |
| `api_key` | string, min. 1 | no | `writeOnly`: encrypted at rest and masked as `********` in responses (dmstr/api-configuration-bundle 0.6, readers need the permission `apiconfig:read`); the client decrypts it and sends the `Authorization: Bearer` header only when it is set |
| `timeout` | integer 1 to 55 | no | HTTP timeout and maximum duration per request, default 30 |
| `verify_ssl` | boolean | no | verify the TLS certificate and host name, default `true` |

No other properties are allowed. Example:

```json
{
  "name": "default",
  "active": true,
  "configJson": {
    "firecrawl": true,
    "type": "firecrawl",
    "endpoint_type": "rest",
    "base_url": "https://firecrawl.example.org",
    "api_key": "fc-...",
    "timeout": 30
  }
}
```

The timeout is capped at 55 seconds because synchronous scrape and map requests are served within the PHP request timeout of the web server, typically 60 seconds (for example php-fpm's `request_terminate_timeout`): a longer client timeout would let the server kill the worker before the client can report the timeout as an error.

Names of `firecrawl` connections are unique among `firecrawl` connections (`FirecrawlConnectionNameUnique`, violation on `name`); other connection types may reuse a name.

## Seed command

```bash
bin/console firecrawl:connection:seed [<name>] [--url=URL] [--api-key=KEY] [--timeout=SECONDS] [--no-verify-ssl]
bin/console firecrawl:connection:seed --file=FILE
```

Creates the `firecrawl` connection `<name>` (default `default`) and/or the connections of a seed file, or brings them in line with the given values. `--url` and `--api-key` default to `dmstr_firecrawl.seed.base_url` and `dmstr_firecrawl.seed.api_key`; an explicitly empty `--api-key=` stores no key. The seed file is a JSON list of connections; `config` takes `base_url` (required), `api_key`, `timeout` and `verify_ssl` with the meaning of the schema, and unknown keys are an error:

```json
[
  {
    "name": "default",
    "config": {
      "base_url": "https://firecrawl.example.org",
      "api_key": "REPLACE-WITH-YOUR-FIRECRAWL-API-KEY"
    }
  }
]
```

The single connection is seeded first, then the file in order. The command

- prints a note and exits with 0 without touching the database when the URL is empty (after trimming) and there is no `--file`; with `--file`, the single connection is skipped silently;
- creates a missing connection (active);
- updates an existing connection with a different `configJson` in place and keeps its active flag;
- leaves an existing connection with an equal `configJson` alone (no flush, `updatedAt` unchanged);
- validates through the validator before writing, so the schema and the name uniqueness apply as through the API, and drops a rejected update from the managed entity;
- seeds the remaining entries when one fails and then exits with 1;
- never prints an API key and never contacts the instance.

The seed replaces the whole `configJson`, so it suits environments that take the connection from their configuration. Where connections are maintained through the API, leave the seed values empty and do not run the command routinely: a run without `--api-key` would remove a key set through the API.

## Health probe

`GET /api/admin/api_configurations/{id}/health` and `app:api-configuration:health <id>` call `GET /v2/crawl/active` through `FirecrawlHealthClient`:

- success: `status: ok`, `authenticated: true`, message `Firecrawl reachable, N active crawl(s).`, `metadata.custom.activeCrawls` (int);
- failure: `status: error`, `authenticated: false`, a message with the cause and `error.code`:
  - `UNAUTHENTICATED`: HTTP 401 or 403, whatever the body format (Firecrawl answers a wrong key with a plain-text `Forbidden`);
  - `PROTOCOL`: the instance answered with 2xx, but not with a JSON object or without a `crawls` list;
  - `UNREACHABLE`: everything else, including network, TLS and timeout failures, HTTP 5xx and an unusable configuration.

`FirecrawlHealthClient::classify(\Throwable)` exposes the mapping. Creating the health client performs no I/O.

## Client

`FirecrawlClientFactoryInterface::create(array $configJson)` returns a `FirecrawlClientInterface` for one connection; resolve the connection first with `FirecrawlConnectionResolver::resolve(?string $reference)` (IRI, full or partial UUID, or name; null selects the single active `firecrawl` connection and answers 422 when there is none or several).

| Method | Request |
|---|---|
| `scrape(array $body)` | `POST /v2/scrape` |
| `map(array $body)` | `POST /v2/map` |
| `crawl(array $body)` | `POST /v2/crawl` |
| `batchScrape(array $body)` | `POST /v2/batch/scrape` |
| `getCrawl(string $id)` | `GET /v2/crawl/{id}` |
| `getBatchScrape(string $id)` | `GET /v2/batch/scrape/{id}` |
| `activeCrawls()` | `GET /v2/crawl/active` |
| `getUrl(string $absoluteUrl)` | `GET` of a `next` URL; scheme, host and port are replaced by the base URL's (SSRF guard), path and query are kept |

Bodies are forwarded verbatim as JSON, responses are decoded JSON objects. Every method throws `FirecrawlApiException` (`getStatusCode()`, `getResponseBody()` with up to 500 bytes) on HTTP >= 400 and on transport failures (status null, the Symfony transport exception as previous), and `FirecrawlProtocolException` (a subclass) when a 2xx body is not a JSON object.

## Test seam

Under `when@test`, alias the factory interface to the fake (public service):

```yaml
when@test:
    services:
        Dmstr\Firecrawl\Testing\FakeFirecrawlClientFactory:
            public: true
        Dmstr\Firecrawl\Client\FirecrawlClientFactoryInterface:
            alias: Dmstr\Firecrawl\Testing\FakeFirecrawlClientFactory
```

A test fetches the fake from the container, scripts answers per method and path (optionally per base URL) and reads the recorded requests afterwards:

```php
$fake = static::getContainer()->get(FakeFirecrawlClientFactory::class);
$fake->serve('GET', '/v2/crawl/active', ['success' => true, 'crawls' => []]);
$fake->queue('POST', '/v2/crawl', ['success' => true, 'id' => 'job-1', 'url' => 'https://fc.example.org/v2/crawl/job-1']);
$fake->rejectCredentials('https://other.example.org');
// ... code under test ...
self::assertSame(1, $fake->getRequestCount(null, 'POST', '/v2/crawl'));
```

`serve()` answers persistently, `queue()` and `queueException()` once (FIFO), `failWith()` and `rejectCredentials()` make every call throw, `reset()` forgets everything. Unscripted calls throw a `\LogicException`, so a test never passes by accident. The complete API is documented on `FakeFirecrawlClientFactory`. Nothing in `src/Testing/` is registered outside the test environment.

## Service wiring

The bundle imports [`config/services.yaml`](config/services.yaml), which autowires and autoconfigures the namespace `Dmstr\Firecrawl\` (without the bundle class, entities, API resources, the per-connection client, the exceptions, the health client and `Testing/`). The host application's `_instanceof` rules do not reach these services, so the file declares its own:

- `Dmstr\OpenApiJsonSchema\Interface\SchemaProviderInterface` → `dmstr_openapi_json_schema.provider` (schema branch of `configJson`);
- `Dmstr\ApiConfiguration\Extension\ApiExtensionInterface` → `dmstr_api_configuration.extension` (connection type);
- `Dmstr\SymfonyJobQueue\Service\Job\JobProcessorInterface` → `dmstr.job_queue.processor` (job processors).

Input schemas of the bundle's operations are found by its own `OperationInputSchemaResolver` (`dmstr_firecrawl.input_schema_resolver`, tag `dmstr_openapi_json_schema.input_resolver` with priority 10), which joins the resolver chain, so the OpenAPI `requestBody` and `InputBodyValidator` read the same file:

- `config/schemas/<operation-with-dashes>-input.json` for Doctrine-less resources, e.g. operation `firecrawl_scrape` → `config/schemas/firecrawl-scrape-input.json`;
- `src/Entity/**/<Entity>/<verb>.input.json` for entities, e.g. operation `firecrawl_job_start` → `src/Entity/**/FirecrawlJob/start.input.json`.

Services that log receive the Monolog channel `firecrawl` through the binding `$firecrawlLogger`.

## Pass-through resources

Four synchronous operations forward a request body unchanged to a Firecrawl instance and return the decoded answer verbatim (HTTP 200, `application/json`). Nothing is stored. They require the permission `firecrawl:passthru:execute`. They accept `Accept: application/json` and `application/ld+json` and answer `application/json` either way; errors are problem documents (`application/problem+json`) under `application/json` and hydra errors under `application/ld+json`.

| Operation | Route | Firecrawl | CLI twin |
|---|---|---|---|
| `firecrawl_scrape` | `POST /api/firecrawl/scrape` | `POST /v2/scrape` | `firecrawl:scrape <url>` |
| `firecrawl_map` | `POST /api/firecrawl/map` | `POST /v2/map` | `firecrawl:map <url>` |
| `firecrawl_crawl` | `POST /api/firecrawl/crawl` | `POST /v2/crawl` | `firecrawl:crawl <url>` |
| `firecrawl_batch_scrape` | `POST /api/firecrawl/batch_scrape` | `POST /v2/batch/scrape` | `firecrawl:batch-scrape <urls>...` |

The body is validated against the operation's input schema, `config/schemas/<operation-with-dashes>-input.json` (e.g. `firecrawl-batch-scrape-input.json`; draft-07, also the OpenAPI `requestBody`): `url` (or the non-empty list `urls` for batch scrape) must be an absolute http(s) URL. The common Firecrawl options are documented there, and any other option is forwarded unchanged (`additionalProperties: true`). Malformed JSON is answered with 400, a schema violation with 422.

The connection is chosen with `apiConfiguration` (IRI, full or partial UUID, or name of an active `firecrawl` connection), given in the body or as query parameter; the query parameter wins. Without it the single active `firecrawl` connection is used, and 422 is returned when there is none or several. `apiConfiguration` is consumed by the bundle and never forwarded.

Requests are synchronous and limited by the connection `timeout` (at most 55 s), because they are served within the PHP request timeout (typically 60 s). Long jobs run on the instance: crawl and batch scrape only start them and return their `id` and status `url`; the pass-through resources neither store nor track them (use the job reference table below to track a crawl). A Firecrawl failure is answered with 502 and an `application/problem+json` document whose `detail` carries the remote status and a body excerpt, e.g. `Firecrawl scrape failed: HTTP 500: <body excerpt>`; the processor builds this document itself, because API Platform replaces the detail of every 5xx with "Internal Server Error" outside debug mode.

The console twins use the same `Passthru\PassthruService`, so validation, connection choice and logging are identical: `--connection=<ref>` selects the connection, `--body='{"formats": ["markdown"]}'` adds options (the url argument wins over `url` in it, the urls arguments over `urls`), the answer is printed as pretty JSON, and errors exit with 1. Every call writes one info line to the `firecrawl` log channel with operation, connection, target URL(s), acting user (`cli` on the console) and outcome.

## Job reference table

`Dmstr\Firecrawl\Entity\Refs\FirecrawlJob` (table `ref_firecrawl_job`, migrations `Dmstr\Firecrawl\Migrations\Version20261003000000` and `Version20261003001023`, see [Migrations](#migrations)) mirrors jobs on Firecrawl instances. Every row belongs to the `firecrawl` connection it runs on (`apiConfiguration`, FK ON DELETE CASCADE); a remote id is unique per connection (partial unique index on `(api_configuration_id, firecrawl_id) WHERE firecrawl_id IS NOT NULL`, because scrape rows have no remote id).

| Operation | Route | Role | Result |
|---|---|---|---|
| `firecrawl_job_start` | `POST /api/refs/firecrawl_jobs` | `firecrawl:job:write` | 201; scrape → `completed` with `resultSummary` (`metadata`, `markdown_length`), crawl and batch scrape → `scraping` with `firecrawlId`, remote error → `failed` with `errorMessage` |
| `firecrawl_job_sync` | `POST /api/refs/firecrawl_jobs/{id}/sync` | `firecrawl:job:write` | 200; status and `resultSummary` (`total`, `completed`, `credits_used`) from `GET /v2/crawl/{id}` (batch scrape: `GET /v2/batch/scrape/{id}`); a remote error keeps the status and sets `errorMessage`, a row without remote id is returned unchanged |
| `firecrawl_job_scan` | `POST /api/refs/firecrawl_jobs/scan` | `firecrawl:job:write` | 201 `Job` of type `firecrawl_job_scan` |
| `firecrawl_job_import` | `POST /api/refs/firecrawl_jobs/{id}/import` | `firecrawl:job:write` | 201 `Job` of type `firecrawl_job_import`, see [Importing results](#importing-results) |
| `GET` item / collection | `/api/refs/firecrawl_jobs` | `firecrawl:job:read` | filters `status`, `type`, `url` (partial), `apiConfiguration` |

The start body (`src/Entity/Refs/FirecrawlJob/start.input.json`) takes `type` (`scrape`, `crawl` or `batch_scrape`, default `crawl`), `url` (http(s), required for scrape and crawl), `urls` (non-empty list of http(s) URLs, required for `batch_scrape`; the row's `url` is the first entry, the full list is stored in `options.urls` and sent as the body `['urls' => …] + options` to `POST /v2/batch/scrape`), `options` (Firecrawl request options such as `formats`, `limit`, `scrapeOptions`; the URL argument wins over `options.url`) and the optional `apiConfiguration` (IRI, UUID or name; default: the single active connection). Malformed JSON is answered with 400, schema violations and unusable connections with 422. The scan body (`scan.input.json`) takes only the optional `apiConfiguration`.

The scan creates a row (`type = crawl`, `status = scraping`) for every active crawl (`GET /v2/crawl/active`) of all `firecrawl` connections, or of the one in the body, that is not mirrored yet; inactive connections are skipped and existing rows are never changed (`sync` refreshes them). It covers crawls only, because Firecrawl lists only active crawls; batch scrapes are tracked from their start row. The job result is the summary `created`, `unchanged`, `scanned`, `skipped`, `failed`, `connections[]`; the job fails when every scanned connection failed or when the referenced connection no longer exists.

Console twins: `firecrawl:jobs [--status] [--type] [--connection] [--limit=50]`, `firecrawl:jobs:start <type> <url>... [--connection] [--options JSON]` (one URL for scrape and crawl, one or more for `batch_scrape`, as with `firecrawl:batch-scrape <urls>...`), `firecrawl:jobs:sync <id>` (full or partial UUID), `firecrawl:jobs:scan [--connection]` (synchronous, no queue job), `firecrawl:jobs:import <id> <collection> [--delete-vanished]` (synchronous, no queue job). Start, sync, scan and import exit non-zero on failure.

## Importing results

`POST /api/refs/firecrawl_jobs/{id}/import` (operation `firecrawl_job_import`, permission `firecrawl:job:write`) imports the result of a `completed` crawl or batch scrape into a knowledge collection, one document per page, and answers 201 with a `Job` of type `firecrawl_job_import` that runs in the Messenger worker. The body (`src/Entity/Refs/FirecrawlJob/import.input.json`, draft-07, no other properties) takes:

- `collection` (required): IRI, UUID or name of the target knowledge collection;
- `deleteVanished` (boolean, default `false`): remove documents of a page that is no longer in the crawl (crawls only, see below).

```json
{"collection": "/api/admin/collections/0199a5b2-…", "deleteVanished": true}
```

Before a job is created, the request is checked: 404 for an unknown job reference, 400 for malformed JSON, 422 for a schema violation, a job that is not of type `crawl` or `batch_scrape`, a job that is not `completed` (run `sync` first), `deleteVanished` on a batch scrape, an unknown collection, or a collection holding crawler-ingested chunks (one source per collection). Nothing is stored when a check fails.

### Bundle and application

The bundle reads, paginates and normalises the result; the application turns a page into a document. The port is `Dmstr\Firecrawl\Import\PageImporterInterface` with the value objects `ImportTarget` (collection id, name and site as strings), `CrawledPage` (normalised URL, Markdown, title, status code) and `PageOutcome` (`created`, `replaced`, `skipped`, `failed` with reason). The application aliases the interface to its implementation in its own `config/services.yaml` (mandatory, see [Minimal configuration](#minimal-configuration)); the bundle neither ships nor instantiates one:

```yaml
services:
    Dmstr\Firecrawl\Import\PageImporterInterface: '@App\Import\FirecrawlPageImporter'
```

The implementation decides how a page is stored (for example as a document of a knowledge collection with chunks and embeddings), how a collection reference is resolved and which documents count as imported from Firecrawl.

`Import\JobImporter` orchestrates one import (shared by the job processor and the CLI), `Import\ResultReader` reads the result, `Import\SetextHeadingNormalizer` and `Import\UrlNormalizer` normalise pages.

### Reading the result

The reader fetches the status page (`GET /v2/crawl/{id}` or `GET /v2/batch/scrape/{id}`) and then follows the top-level `next` link one result page at a time, so memory does not grow with the page count. The `next` link must point to this job's own result endpoint (path `/v2/crawl/{id}` or `/v2/batch/scrape/{id}` with the job's remote id, any query); anything else fails the import. Its scheme and host are not checked, because the client always replaces them with the connection's base URL (Firecrawl emits `next` with `http://`). A `next` inside page content is never followed.

Every result entry with Markdown and a usable source URL becomes a page; an entry without them counts as failed. The page URL is `metadata.sourceURL` (or `metadata.url`), normalised: fragment removed, scheme and host lower-cased, trailing slash dropped except for the root path, `www.` and the query kept as they are. The normalised URL identifies the page in the collection; a normalised URL longer than 2048 characters counts as failed.

### Setext headings

Firecrawl often renders headings as setext (`Title` underlined with `===` or `---`), which the Markdown chunker does not split at. Before a page is stored, a setext underline directly below a single paragraph line is rewritten to an ATX heading (`# Title`, `## Title`). Horizontal rules (`---` after a blank line or at the top), tables, list items, blockquotes, indented and fenced code and a YAML front matter block stay unchanged; an underline below a paragraph of several lines is left alone on purpose. The rewrite is idempotent.

### Created, replaced, skipped

The application matches a page by its normalised URL among the collection's documents imported from Firecrawl (uploaded documents are never matched, replaced or removed):

- not found: `created`;
- found, same content hash (sha256 of the stored Markdown) and embedded: `skipped`;
- found with a different hash, or not embedded (e.g. failed earlier): `replaced` in place (same document id and IRI).

Pages that did not change on the site may still differ byte for byte between two crawls (timestamps, dynamic content); they then count as `replaced`, never as lost. A page whose content equals another document of the collection, an oversized page or a page whose extraction fails counts as `failed`; the import continues with the next page.

### Report

The job's `resultData` (also printed by the CLI):

```json
{
  "created": 12, "replaced": 3, "skipped": 40, "deleted": 2, "failed": 1, "pages": 56,
  "site": "www.example.org",
  "failures": [{"url": "https://www.example.org/a", "reason": "…", "document": "/api/admin/documents/…"}],
  "deletion": "skipped",
  "deletionSkippedReason": "1 page(s) failed; nothing is removed after an incomplete import."
}
```

`pages` counts the result entries read, `site` is the lower-cased host of the crawl's start URL (`null` for a batch scrape), `failures[].document` is present when a document is involved, and `deletion` is `off` (not requested), `done` (with the count in `deleted`) or `skipped` (with `deletionSkippedReason`). The job reports its progress in percent, per page and within a page while its chunks are embedded. It fails when every page failed or the result holds no pages; a partial report is kept in `resultData` of a failed job as well.

### Removing vanished pages

With `deleteVanished`, documents imported from Firecrawl for the same site (the host of the crawl's start URL) whose URL is not in this crawl are removed together with their chunks, vectors and file. It runs only when the job is a crawl, every result page was read and no page failed; otherwise the report says `deletion: skipped` and why. Uploaded documents and documents of other sites are never removed. Batch scrapes do not support it (an arbitrary URL list says nothing about missing pages), and their documents belong to no site, so a later crawl never removes them.

### Result lifetime and expiry

Firecrawl keeps results only for a while: upstream, a pg_cron job removes completed scrape jobs (including batch scrape pages) after 1 hour and crawl groups after their TTL, 24 hours by default; a self-hosted instance may differ. Import shortly after `sync` reports `completed`. Firecrawl does not document how an expired result looks, so the import detects it heuristically: HTTP 404, a 2xx answer with `success: false`, or an `expiresAt` in the past. An expired or unknown result, a remote status other than `completed` and a foreign continuation link fail the job with a readable message and without a retry, also when the result expires between two result pages (pages read before stay imported, nothing is removed). Transport failures are retried by Messenger; a re-run is an upsert and skips the pages already imported.

### Batch scrapes

A batch scrape is started with `type: batch_scrape` and `urls`, refreshed with `sync` and imported like a crawl. It is not covered by `scan` (Firecrawl lists only active crawls) and does not support `deleteVanished`.

### Permissions

The import requires `firecrawl:job:write`. Whoever holds it can therefore create, replace and remove documents through the import, although the document writes of the application have their own permission. Map `firecrawl:job:write` only to roles that may write documents.

### CLI twin

```bash
bin/console firecrawl:jobs:import <id> <collection> [--delete-vanished]
```

Runs the same checks and the same import synchronously, without a queue job (like `firecrawl:jobs:scan`); `<id>` is a full or partial UUID of the job reference, `<collection>` an IRI, UUID or name. Prints the report as JSON and exits non-zero when a check or the import fails (the partial report is printed as well). Every import writes one info line to the `firecrawl` log channel and one warning per failed page.

## Crawl onboarding tools (MCP)

Four read-only tools serve a crawl onboarding conversation with an agent; they are API Platform `McpTool` operations without HTTP route (`ApiResource/CrawlOnboarding.php`), served through dmstr/mcp-endpoint-bundle at `/mcp/<slug>`, and all require the permission `firecrawl:onboarding:use`. The three Firecrawl tools contact Firecrawl through the connection named in `connection` (or the single active one, as above) and store nothing: no Firecrawl job reference, no document. They return aggregates, never page HTML, so the model does not read the raw pages. Every result is plain JSON (`outputFormats` `json`), without JSON-LD keys. A Firecrawl failure is a regular result with `status: failed` (or `total: 0`) and `error`, not a tool error, because a model handles a result that names the failure more reliably; an unusable connection stays an error.

| Tool | Firecrawl | Returns |
|---|---|---|
| `crawl_sample_start` | one URL: `POST /v2/crawl` from it, `limit` 2 to 25 (default 10), `maxDiscoveryDepth` 2; several URLs (up to 5): `POST /v2/batch/scrape` of exactly these; both `formats: [rawHtml]`, `onlyMainContent: false` | `status` (`started` or `failed`), `jobId`, `kind` (`crawl` or `batch`), `error` |
| `crawl_sample_analyze` | polls the job of `jobId` and `kind`, then reads its results (following `next`) | `status`, `jobId`, `total`, `completed`, `pages` (url, statusCode, title, error), `candidates`, `alwaysExcluded`, `form`, `error` |
| `crawl_map_site` | `POST /v2/map` with `sitemap: include`, `limit` 1 to 5000 (default 2000) | `total`, `limitReached`, `hosts`, `languages`, `sections`, `sampleUrls`, `error` |
| `crawl_onboarding_reply` | none | the arguments unchanged: `step`, `message`, `form`, `plan`, `openQuestions` |

**Boilerplate analysis** (`Onboarding\BoilerplateAnalyzer`, based on an earlier Python HTML analyzer) looks for the page regions that should not reach the chunks:

- *Category match*: a semantic tag (`nav`, `header`, `footer`, `aside`) or a class or id matching a boilerplate pattern (cookie/consent, navigation, footer, sidebar, language, contact widget, social). Only root containers count, because Firecrawl's `excludeTags` removes the whole subtree.
- *Repetition*, the extension over the original analyzer: an element with class or id near the top of `body` whose text is identical on at least two sample pages. Main content differs between pages, so a block with the same text everywhere is boilerplate even without a telling name. Only the outermost repeated block of a branch is proposed.
- *Preselection*: a candidate on every sample page with identical text, or a strong category (cookie/consent, navigation, footer, language, social, contact widget, `nav`, `footer`) on every page. `header` and `aside` with differing text stay unticked, because both also occur inside articles. With a single sample page there is no comparison, and every category match except `header` and `aside` is preselected.

`script`, `style` and `noscript` are always removed and not offered.

**The form** (`Onboarding\BoilerplateForm`) is built on the server: `{id: "boilerplate", schema, value}`, where `schema.properties.exclude` is an array with `uniqueItems` and an `enum` of the candidate selectors (rendered by Jedison as a checkbox list, labels in `items.x-enumTitles`), plus the boolean `onlyMainContent`, and `value` carries the preselection. The agent does not copy it: it names the form as `{"id": "boilerplate"}` in its answer, and `crawl_onboarding_reply` puts in the form stored for the sample job (`Onboarding\FormStore` on `cache.app`, 24 h, found through `plan.sampleJob.jobId`). A model asked to copy the schema unchanged dropped a property while `required` kept it, so the form could not be submitted; with the stored form the choices are exactly the selectors found in the sample pages, never ones injected through page content or invented by the model. The ticked selectors become Firecrawl's `scrapeOptions.excludeTags`, which accepts tag names and CSS selectors.

**Sample job.** Repeating regions can only be found by comparing pages, so one start URL starts a small crawl from it, and several start URLs a batch scrape of exactly these pages (`Onboarding\SamplePageFetcher`). `crawl_sample_start` returns at once; `crawl_sample_analyze` waits for the job. A tool call has to end before the MCP client gives up (Neuron AI's streamable HTTP transport waits 30 s unless the `mcp` connection sets `timeout`) and within the PHP request timeout (typically 60 s), so it polls for at most 20 s; a job that is not done by then answers `status: pending` with its progress, and the next call with the same `jobId` continues. Firecrawl keeps job results for 24 h.

**Answer tool.** `crawl_onboarding_reply` is the terminal tool through which the agent gives the answer of a turn; its result is the answer, validated against the agent's `responseSchema`. It exists because of an observed failure: with tools on offer and the answer asked for as JSON text, Claude Sonnet 5.5 often ended a turn after a tool result by calling a tool that does not exist (`__none__`, `__noop`), which the Messages API drops (`stop_reason: tool_use`, no content), so the turn had no answer; with a real tool to answer through it answered in every probe (2026-10-14). Its input schema is flat (`form` and `plan` plain objects), because Neuron AI passes only the top level of a tool schema to the model; anything but a non-empty `form` object counts as "no form" (models send the string `"null"`), and empty objects stay `{}` (`preserve_empty_objects`).

**Endpoint.** The tools are served through dmstr/mcp-endpoint-bundle. The application seeds a read-only endpoint, for example `crawl-onboarding`, whose tool allowlist holds the four tools, with a required permission of the application's choice (it must be registered). The agent that conducts the onboarding reaches that endpoint through an MCP connection that forwards the user's token (`forward_jwt` in dmstr/api-configuration-bundle), so the tools act as the person chatting, who needs the endpoint's permission and `firecrawl:onboarding:use`. The tools need an active `firecrawl` connection.

**Example agent.** The agent proposes, a human decides: it only reads, and its result is a crawl plan in its structured answer; it never stores, starts or schedules a crawl. It is started with one to five sample pages of a site (pages of different types, such as the home page, a list page and a detail page, give the comparison the most to work with) and answers every turn through `crawl_onboarding_reply`, with a form per step:

| Step | Tool | Form offered | Decided into `plan` |
|---|---|---|---|
| `boilerplate` | `crawl_sample_start` with all sample pages, then `crawl_sample_analyze` until the job is done | `boilerplate` (stored form): checkbox list of the regions found, preselected; `onlyMainContent` | `startUrls`, `sampleJob` |
| `discovery` | `crawl_map_site` on the start of the site | `scope`: languages (when the site has several), page `limit` | `scrapeOptions` (`onlyMainContent`, `excludeTags`) |
| `topics` | none | `topics`: path prefixes as topics, noise prefixes to exclude | `languages`, `limit` |
| `review` | none | none | `topics`, `includePaths`, `excludePaths`; `openQuestions` names what is left (collection, schedule, credential) |

The answer object has the keys `step`, `message` (what was found and what the user is asked to decide), `form` (`{id, schema, value}` or null; the forms of the later steps are built by the model from the `crawl_map_site` result), `plan` (`startUrls`, `sampleJob` with `jobId` and `kind`, `scrapeOptions`, `languages`, `limit`, `topics` with `name` and `pathPrefix`, `includePaths`, `excludePaths` as Firecrawl regular expressions) and `openQuestions` (at most five). A client renders `form.schema` with `form.value` as an editable form and sends the submitted value as the next user message, `{"form": "<id>", "value": {…}}`; a plain-text answer works as well. The plan maps onto a crawl profile (`discover`, `crawl` and `import` sections), which the user creates under `/api/admin/crawl_profiles`. The agent's instructions and `responseSchema` belong to the application.

## Migrations

The bundle registers its migrations path `./migrations` under the namespace `Dmstr\Firecrawl\Migrations`. Run them with the application's migrations:

```bash
php bin/console doctrine:migrations:migrate
```

| Migration | Change |
| --- | --- |
| `Dmstr\Firecrawl\Migrations\Version20261003000000` | baseline: creates the table `ref_firecrawl_job` with the index `idx_firecrawl_job_status`, unless the table already exists |
| `Dmstr\Firecrawl\Migrations\Version20261003001023` | links `ref_firecrawl_job` to its `firecrawl` connection: column `api_configuration_id` (FK ON DELETE CASCADE onto `dmstr_api_configuration`), partial unique index on the remote id; existing rows are deleted |
| `Dmstr\Firecrawl\Migrations\Version20261105130000` | creates the table `firecrawl_crawl_profile` |
| `Dmstr\Firecrawl\Migrations\Version20261106120000` | renames the converter connection link of a crawl profile and migrates `converter` connections to the type `file-converter` |
| `Dmstr\Firecrawl\Migrations\Version20261106140000` | adds the `import` section to `firecrawl_crawl_profile` |
| `Dmstr\Firecrawl\Migrations\Version20261106150000` | drops the file converter link of a crawl profile (the only active `file-converter` connection is used) |

Existing databases store these class names in their migrations table, so they never change.

**Ordering.** Doctrine's default comparator orders migrations by their fully qualified class name, so no custom comparator is needed: the bundle's migrations run in the order of the table above, and the only foreign table they reference, `dmstr_api_configuration`, is created by dmstr/api-configuration-bundle, whose namespace `Dmstr\ApiConfiguration\Migrations` sorts before `Dmstr\Firecrawl\Migrations`. An application that orders migrations of all namespaces by their version timestamp gets the same order, because the baseline is older than every other migration of the bundle.

**Existing installations.** Before the baseline existed, `ref_firecrawl_job` was created by the host application's own migration, and `Version20261003001023` and later are already recorded as executed there. On such a database only the baseline is new: it runs, finds the table and changes neither its structure nor its rows, and Doctrine records it as executed. The baseline is not skipped, because a skipped migration is not recorded and would stay pending. The application's own migration can stay as it is; it must not drop the table.

## Testing

The PHPUnit configuration `./phpunit.dist.xml` has two suites:

- `unit` (`./tests/Unit`): no kernel, no database.
- `integration` (`./tests/Integration`): boots the test kernel `./tests/Kernel/TestKernel.php`, a minimal host application with the dependency bundles, the scripted `FakeFirecrawlClientFactory` as client factory, an in-memory `PageImporterInterface`, header-based test authentication and a test permission voter. Tests in the group `database` need PostgreSQL from `DATABASE_URL` (default in `phpunit.dist.xml`); their schema comes from the migrations, the bundle's own ones included.

```bash
composer install
vendor/bin/phpunit --testsuite unit
vendor/bin/phpunit --testsuite integration --exclude-group database
# with a PostgreSQL server:
DATABASE_URL='postgresql://app:app@127.0.0.1:5432/firecrawl_test?serverVersion=18&charset=utf8' vendor/bin/phpunit
```

Apart from the live test below, no test contacts a Firecrawl instance; the client is replaced by `Testing\FakeFirecrawlClientFactory`.

A smoke test against a real Firecrawl instance (group `live`, `./tests/Unit/Live/FirecrawlLiveTest.php`) is skipped unless `FIRECRAWL_LIVE_URL` and `FIRECRAWL_LIVE_API_KEY` are set:

```bash
FIRECRAWL_LIVE_URL=https://firecrawl.example.org FIRECRAWL_LIVE_API_KEY=fc-... \
    vendor/bin/phpunit --group live
```

## License

MIT, see `./LICENSE`.
