<?php

// file generated with AI assistance: Claude Code - 2026-11-05 12:00:00 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\Connection;

use Dmstr\ApiConfiguration\ApiClient\ApiClientInterface;
use Dmstr\ApiConfiguration\Extension\ApiExtensionInterface;
use Dmstr\OpenApiJsonSchema\Interface\SchemaProviderInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The `file-converter` connection type: the service that turns the document files
 * of a crawl into Markdown (`converter/` of the application). Like
 * {@see FirecrawlExtension} it contributes its schema branch to
 * `ApiConfiguration.configJson` and the health client of the connection.
 */
final class FileConverterExtension implements ApiExtensionInterface, SchemaProviderInterface
{
    public const string NAME = 'file-converter';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
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
        return __DIR__.'/file-converter.schema.json';
    }

    /**
     * @param array<string, mixed> $config
     */
    public function createClient(array $config): ApiClientInterface
    {
        return new FileConverterHealthClient($config, $this->httpClient);
    }
}
