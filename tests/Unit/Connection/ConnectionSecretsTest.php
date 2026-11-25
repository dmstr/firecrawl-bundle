<?php

// file generated with AI assistance: Claude Code - 2026-11-25 20:48:51 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\Tests\Unit\Connection;

use Dmstr\ApiConfiguration\Security\ConfigSecrets;
use Dmstr\ApiConfiguration\Security\SecretSchemaResolver;
use Dmstr\Firecrawl\Connection\FileConverterExtension;
use Dmstr\Firecrawl\Connection\FirecrawlExtension;
use Dmstr\Firecrawl\Testing\FakeFirecrawlClientFactory;
use Dmstr\Firecrawl\Tests\Fixtures\FirecrawlSecrets;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;

/**
 * The secrets of the bundle's connection schemas: exactly the keys marked
 * `writeOnly` are encrypted at rest and masked on read by
 * dmstr/api-configuration-bundle; `firecrawl` has `api_key`, `file-converter`
 * none.
 */
final class ConnectionSecretsTest extends TestCase
{
    /**
     * @return iterable<string, array{string, list<list<string>>}>
     */
    public static function secretPaths(): iterable
    {
        yield 'firecrawl api_key' => [(new FirecrawlExtension(new FakeFirecrawlClientFactory()))->getSchemaPath(), [['api_key']]];
        yield 'file-converter none' => [(new FileConverterExtension(new MockHttpClient()))->getSchemaPath(), []];
    }

    /**
     * @param list<list<string>> $expected
     */
    #[DataProvider('secretPaths')]
    public function testSecretKeysAreMarkedWriteOnly(string $schemaPath, array $expected): void
    {
        $schema = json_decode((string) file_get_contents($schemaPath), true, 512, \JSON_THROW_ON_ERROR);

        self::assertSame($expected, SecretSchemaResolver::collectSecretPaths($schema));
    }

    public function testTheApiKeyIsEncryptedAndMasked(): void
    {
        $secrets = FirecrawlSecrets::create();
        $config = ['firecrawl' => true, 'type' => 'firecrawl', 'endpoint_type' => 'rest', 'base_url' => 'https://firecrawl.example.org', 'api_key' => 'fc-clear-secret'];

        $stored = $secrets->encrypt($config);

        self::assertStringStartsWith(ConfigSecrets::PREFIX, $stored['api_key']);
        self::assertStringNotContainsString('fc-clear-secret', json_encode($stored, \JSON_THROW_ON_ERROR));
        self::assertSame($config['base_url'], $stored['base_url']);
        self::assertSame(ConfigSecrets::MASK, $secrets->mask($stored)['api_key']);
        self::assertSame($config, $secrets->decrypt($stored));
    }
}
