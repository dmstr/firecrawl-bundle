<?php

// file generated with AI assistance: Claude Code - 2026-10-03 00:27:44 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\Tests\Integration;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * OpenAPI discovery of dmstr/firecrawl-bundle: the operations with their
 * operationIds, the request bodies taken from the bundle's input schema
 * files (through its own input-schema resolver; only `$schema` is dropped),
 * the `apiConfiguration` query parameter and the 502 response of the four
 * pass-through operations, and the `firecrawl` branch of the
 * ApiConfiguration `configJson` schema. Boots the kernel, needs no database.
 */
final class FirecrawlOpenApiTest extends ApiTestCase
{
    protected static ?bool $alwaysBootKernel = true;

    /** @var array<string, mixed> */
    private array $openApi;

    protected function setUp(): void
    {
        $response = static::createClient()->request('GET', '/api/docs.jsonopenapi', ['headers' => ['Accept' => 'application/vnd.openapi+json']]);
        self::assertResponseIsSuccessful();
        $this->openApi = json_decode($response->getContent(), true, 512, \JSON_THROW_ON_ERROR);
    }

    /**
     * Path, method, operationId, success status and the bundle-relative
     * input schema file (null: no request body). Seven operations take a body;
     * sync and the item GET take none. The start body is the schema with the
     * `batch_scrape` type, `urls` and the if/then/else on `type`.
     *
     * @return iterable<string, array{string, string, string, int, string|null}>
     */
    public static function operations(): iterable
    {
        yield 'scrape' => ['/api/firecrawl/scrape', 'post', 'firecrawl_scrape', 200, 'config/schemas/firecrawl-scrape-input.json'];
        yield 'map' => ['/api/firecrawl/map', 'post', 'firecrawl_map', 200, 'config/schemas/firecrawl-map-input.json'];
        yield 'crawl' => ['/api/firecrawl/crawl', 'post', 'firecrawl_crawl', 200, 'config/schemas/firecrawl-crawl-input.json'];
        yield 'batch scrape' => ['/api/firecrawl/batch_scrape', 'post', 'firecrawl_batch_scrape', 200, 'config/schemas/firecrawl-batch-scrape-input.json'];
        yield 'start' => ['/api/refs/firecrawl_jobs', 'post', 'firecrawl_job_start', 201, 'src/Entity/Refs/FirecrawlJob/start.input.json'];
        yield 'sync' => ['/api/refs/firecrawl_jobs/{id}/sync', 'post', 'firecrawl_job_sync', 200, null];
        yield 'scan' => ['/api/refs/firecrawl_jobs/scan', 'post', 'firecrawl_job_scan', 201, 'src/Entity/Refs/FirecrawlJob/scan.input.json'];
        yield 'import' => ['/api/refs/firecrawl_jobs/{id}/import', 'post', 'firecrawl_job_import', 201, 'src/Entity/Refs/FirecrawlJob/import.input.json'];
        yield 'item' => ['/api/refs/firecrawl_jobs/{id}', 'get', 'api_refsfirecrawl_jobs_id_get', 200, null];
    }

    #[DataProvider('operations')]
    public function testOperationIsDocumentedWithItsRequestBody(string $path, string $method, string $operationId, int $status, ?string $inputFile): void
    {
        $operation = $this->paths()[$path][$method] ?? null;
        self::assertIsArray($operation, $method.' '.$path);
        self::assertSame($operationId, $operation['operationId']);
        self::assertArrayHasKey((string) $status, $operation['responses']);

        if (null === $inputFile) {
            self::assertArrayNotHasKey('requestBody', $operation);

            return;
        }

        $expected = json_decode((string) file_get_contents($this->bundleDir().'/'.$inputFile), true, 512, \JSON_THROW_ON_ERROR);
        unset($expected['$schema']);
        self::assertSame($expected, $operation['requestBody']['content']['application/json']['schema'] ?? null);
    }

    public function testStartSchemaOffersBatchScrape(): void
    {
        $schema = $this->paths()['/api/refs/firecrawl_jobs']['post']['requestBody']['content']['application/json']['schema'] ?? null;
        self::assertIsArray($schema);

        self::assertSame(['scrape', 'crawl', 'batch_scrape'], $schema['properties']['type']['enum']);
        self::assertSame('array', $schema['properties']['urls']['type']);
        self::assertSame(['urls'], $schema['then']['required']);
        self::assertSame(['url'], $schema['else']['required']);
    }

    public function testImportAnswersWithAJob(): void
    {
        $operation = $this->paths()['/api/refs/firecrawl_jobs/{id}/import']['post'] ?? null;
        self::assertIsArray($operation);

        self::assertSame(['id'], array_column($operation['parameters'] ?? [], 'name'));
        $schema = $operation['responses']['201']['content']['application/ld+json']['schema']['$ref'] ?? '';
        // The output class is the queue Job (API Platform names it `<resource>.Job.jsonld`), not the row.
        self::assertMatchesRegularExpression('#[./]Job\.jsonld$#', $schema);
    }

    public function testCollectionHasTheFilters(): void
    {
        $collection = $this->paths()['/api/refs/firecrawl_jobs']['get'] ?? null;
        self::assertIsArray($collection);

        $parameters = array_column($collection['parameters'], 'name');
        foreach (['status', 'type', 'url', 'apiConfiguration'] as $filter) {
            self::assertContains($filter, $parameters);
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function passthruPaths(): iterable
    {
        yield 'scrape' => ['/api/firecrawl/scrape'];
        yield 'map' => ['/api/firecrawl/map'];
        yield 'crawl' => ['/api/firecrawl/crawl'];
        yield 'batch scrape' => ['/api/firecrawl/batch_scrape'];
    }

    #[DataProvider('passthruPaths')]
    public function testPassthruDocumentsConnectionParameterAndBadGateway(string $path): void
    {
        $operation = $this->paths()[$path]['post'] ?? null;
        self::assertIsArray($operation);

        $parameters = array_column($operation['parameters'] ?? [], null, 'name');
        self::assertArrayHasKey('apiConfiguration', $parameters);
        self::assertSame('query', $parameters['apiConfiguration']['in']);
        self::assertArrayHasKey('502', $operation['responses']);
    }

    public function testConfigJsonHasExactlyOneFirecrawlBranch(): void
    {
        // The branch is the connection schema file verbatim ($schema included).
        $expected = json_decode((string) file_get_contents($this->bundleDir().'/schema.json'), true, 512, \JSON_THROW_ON_ERROR);

        $checked = 0;
        foreach ($this->openApi['components']['schemas'] as $name => $schema) {
            if (!str_starts_with($name, 'ApiConfiguration')) {
                continue;
            }
            $properties = $schema['properties'] ?? [];
            foreach ($schema['allOf'] ?? [] as $part) {
                $properties += $part['properties'] ?? [];
            }
            $branches = $properties['configJson']['anyOf'] ?? null;
            if (null === $branches) {
                continue;
            }

            $firecrawl = array_values(array_filter(
                $branches,
                static fn (array $branch): bool => 'firecrawl' === ($branch['properties']['type']['const'] ?? null),
            ));
            self::assertCount(1, $firecrawl, $name);
            self::assertSame($expected, $firecrawl[0], $name);
            ++$checked;
        }

        self::assertGreaterThan(0, $checked, 'No ApiConfiguration schema with configJson found.');
    }

    /**
     * @return array<string, mixed>
     */
    private function paths(): array
    {
        return $this->openApi['paths'];
    }

    private function bundleDir(): string
    {
        $dir = static::getContainer()->getParameter('dmstr_firecrawl.dir');
        self::assertIsString($dir);

        return $dir;
    }
}
