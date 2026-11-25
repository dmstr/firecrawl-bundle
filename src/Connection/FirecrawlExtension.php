<?php

// file generated with AI assistance: Claude Code - 2026-10-02 23:51:30 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\Connection;

use Dmstr\ApiConfiguration\ApiClient\ApiClientInterface;
use Dmstr\ApiConfiguration\Extension\ApiExtensionInterface;
use Dmstr\Firecrawl\Client\FirecrawlClientFactoryInterface;
use Dmstr\OpenApiJsonSchema\Interface\SchemaProviderInterface;

/**
 * The `firecrawl` connection type of dmstr/api-configuration-bundle.
 *
 * One class serves both registries: as ApiExtensionInterface it creates the
 * health client for `GET /api/admin/api_configurations/{id}/health` and
 * `app:api-configuration:health`; as SchemaProviderInterface it contributes
 * the `firecrawl` branch (the bundle's schema.json) to the unified
 * `ApiConfiguration.configJson` anyOf. The bundle's services.yaml tags it
 * for both (`dmstr_api_configuration.extension`,
 * `dmstr_openapi_json_schema.provider`) through `_instanceof`.
 */
final class FirecrawlExtension implements ApiExtensionInterface, SchemaProviderInterface
{
    public const string NAME = 'firecrawl';

    public function __construct(
        private readonly FirecrawlClientFactoryInterface $clientFactory,
    ) {
    }

    public function getName(): string
    {
        return self::NAME;
    }

    public function getType(): string
    {
        return 'rest';
    }

    public function getSchemaPath(): string
    {
        return \dirname(__DIR__, 2).'/schema.json';
    }

    /**
     * Performs no I/O: the Firecrawl client is created and contacted only
     * when a probe method of the health client is called.
     *
     * @param array<string, mixed> $config
     */
    public function createClient(array $config): ApiClientInterface
    {
        return new FirecrawlHealthClient($config, $this->clientFactory);
    }
}
