<?php

// file generated with AI assistance: Claude Code - 2026-10-02 23:51:30 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\Tests\Unit\Connection;

use Dmstr\Firecrawl\Connection\FirecrawlExtension;
use Dmstr\Firecrawl\Testing\FakeFirecrawlClientFactory;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Kernel-free checks of the `firecrawl` branch of ApiConfiguration.configJson
 * (the bundle's schema.json), validated like ApiConfigurationValidator does
 * (opis, after json_decode(json_encode())).
 */
final class FirecrawlSchemaTest extends TestCase
{
    private const array BASE = [
        'firecrawl' => true,
        'type' => 'firecrawl',
        'endpoint_type' => 'rest',
        'base_url' => 'https://firecrawl.example.org',
    ];

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function validConfigs(): iterable
    {
        yield 'minimal' => [self::BASE];
        yield 'full' => [self::BASE + ['api_key' => 'fc-secret', 'timeout' => 55, 'verify_ssl' => false]];
        yield 'http with port and path' => [['base_url' => 'http://firecrawl:3002/'] + self::BASE];
        yield 'timeout one' => [self::BASE + ['timeout' => 1]];
    }

    /**
     * @param array<string, mixed> $config
     */
    #[DataProvider('validConfigs')]
    public function testValidConfigurationsPass(array $config): void
    {
        self::assertTrue($this->validate($config), json_encode($config));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidConfigs(): iterable
    {
        $withoutBaseUrl = self::BASE;
        unset($withoutBaseUrl['base_url']);

        yield 'missing base_url' => [$withoutBaseUrl];
        yield 'ftp url' => [['base_url' => 'ftp://firecrawl.example.org'] + self::BASE];
        yield 'relative url' => [['base_url' => 'firecrawl.example.org'] + self::BASE];
        yield 'timeout 56' => [self::BASE + ['timeout' => 56]];
        yield 'timeout 0' => [self::BASE + ['timeout' => 0]];
        yield 'timeout as string' => [self::BASE + ['timeout' => '30']];
        yield 'empty api_key' => [self::BASE + ['api_key' => '']];
        yield 'verify_ssl as string' => [self::BASE + ['verify_ssl' => 'no']];
        yield 'unknown property' => [self::BASE + ['url' => 'https://firecrawl.example.org']];
        yield 'type mcp' => [['type' => 'mcp'] + self::BASE];
        yield 'discriminator false' => [['firecrawl' => false] + self::BASE];
        yield 'wrong endpoint type' => [['endpoint_type' => 'file'] + self::BASE];
    }

    /**
     * @param array<string, mixed> $config
     */
    #[DataProvider('invalidConfigs')]
    public function testInvalidConfigurationsAreRejected(array $config): void
    {
        self::assertFalse($this->validate($config), json_encode($config));
    }

    public function testBranchFollowsTheDiscriminatorConvention(): void
    {
        $schema = $this->schema();

        self::assertSame('http://json-schema.org/draft-07/schema#', $schema['$schema']);
        self::assertFalse($schema['additionalProperties']);
        self::assertSame(['firecrawl', 'type', 'endpoint_type', 'base_url'], $schema['required']);
        foreach (['firecrawl' => true, 'type' => 'firecrawl', 'endpoint_type' => 'rest'] as $field => $value) {
            self::assertSame($value, $schema['properties'][$field]['const'], $field);
            self::assertTrue($schema['properties'][$field]['readOnly'], $field);
        }
        self::assertSame('uri', $schema['properties']['base_url']['format']);
        self::assertSame('^https?://', $schema['properties']['base_url']['pattern']);
        self::assertSame(55, $schema['properties']['timeout']['maximum']);
        self::assertSame(30, $schema['properties']['timeout']['default']);
        self::assertTrue($schema['properties']['verify_ssl']['default']);
    }

    public function testExtensionPointsAtTheSchema(): void
    {
        $extension = new FirecrawlExtension(new FakeFirecrawlClientFactory());

        self::assertSame('firecrawl', $extension->getName());
        self::assertSame(FirecrawlExtension::NAME, $extension->getName());
        self::assertSame('rest', $extension->getType());
        self::assertFileExists($extension->getSchemaPath());
        self::assertSame('schema.json', basename($extension->getSchemaPath()));
    }

    /**
     * @return array<string, mixed>
     */
    private function schema(): array
    {
        $path = (new FirecrawlExtension(new FakeFirecrawlClientFactory()))->getSchemaPath();

        return json_decode((string) file_get_contents($path), true, 512, \JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function validate(array $config): bool
    {
        $data = json_decode(json_encode($config, \JSON_THROW_ON_ERROR));
        $schema = json_decode(json_encode($this->schema(), \JSON_THROW_ON_ERROR));

        return (new Validator())->validate($data, $schema)->isValid();
    }
}
