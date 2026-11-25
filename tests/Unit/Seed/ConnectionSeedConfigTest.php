<?php

// file generated with AI assistance: Claude Code - 2026-10-02 23:51:30 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\Tests\Unit\Seed;

use Dmstr\Firecrawl\Command\ConnectionSeedCommand;
use Dmstr\Firecrawl\Connection\FirecrawlExtension;
use Dmstr\Firecrawl\Testing\FakeFirecrawlClientFactory;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Kernel-free checks of the seed command's pure parts: building the
 * configJson, comparing it for the no-op, parsing --timeout and the
 * entries of a seed file. Built
 * configurations are validated against the `firecrawl` schema branch the
 * way ApiConfigurationValidator does (opis, after json_decode(json_encode())).
 */
final class ConnectionSeedConfigTest extends TestCase
{
    private const string URL = 'https://firecrawl.example.org';

    public function testBuildsTheMinimalConfigWithDiscriminators(): void
    {
        self::assertSame([
            'firecrawl' => true,
            'type' => 'firecrawl',
            'endpoint_type' => 'rest',
            'base_url' => self::URL,
        ], ConnectionSeedCommand::buildConfig(self::URL));
    }

    public function testBuildsTheOptionalFields(): void
    {
        self::assertSame([
            'firecrawl' => true,
            'type' => 'firecrawl',
            'endpoint_type' => 'rest',
            'base_url' => self::URL,
            'api_key' => 'fc-secret',
            'timeout' => 50,
            'verify_ssl' => false,
        ], ConnectionSeedCommand::buildConfig(self::URL, 'fc-secret', 50, false));
    }

    public function testDefaultsAreOmitted(): void
    {
        $config = ConnectionSeedCommand::buildConfig(self::URL, '', null, true);

        self::assertArrayNotHasKey('api_key', $config);
        self::assertArrayNotHasKey('timeout', $config);
        self::assertArrayNotHasKey('verify_ssl', $config);
        self::assertArrayNotHasKey('api_key', ConnectionSeedCommand::buildConfig(self::URL, null));
    }

    public function testKeyOrderDoesNotCount(): void
    {
        $config = ConnectionSeedCommand::buildConfig(self::URL, 'fc-secret', 10);

        self::assertTrue(ConnectionSeedCommand::isSameConfig(array_reverse($config, true), $config));
        self::assertFalse(ConnectionSeedCommand::isSameConfig($config, ConnectionSeedCommand::buildConfig(self::URL, 'other', 10)));
        self::assertFalse(ConnectionSeedCommand::isSameConfig($config, ConnectionSeedCommand::buildConfig(self::URL, 'fc-secret')));
        // Values are compared strictly.
        self::assertFalse(ConnectionSeedCommand::isSameConfig(['timeout' => '10'] + $config, $config));
    }

    /**
     * @return iterable<string, array{mixed, ?int}>
     */
    public static function timeouts(): iterable
    {
        yield 'omitted' => [null, null];
        yield 'empty' => ['', null];
        yield 'string' => ['50', 50];
        yield 'int' => [12, 12];
        yield 'negative stays for the schema' => ['-1', -1];
    }

    #[DataProvider('timeouts')]
    public function testParsesTimeout(mixed $value, ?int $expected): void
    {
        self::assertSame($expected, ConnectionSeedCommand::parseTimeout($value));
    }

    public function testNonIntegerTimeoutIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ConnectionSeedCommand::parseTimeout('ten');
    }

    public function testBuiltConfigsMatchTheSchema(): void
    {
        self::assertTrue($this->validate(ConnectionSeedCommand::buildConfig(self::URL)));
        self::assertTrue($this->validate(ConnectionSeedCommand::buildConfig(self::URL, 'fc-secret', 55, false)));
        self::assertFalse($this->validate(ConnectionSeedCommand::buildConfig('ftp://firecrawl.example.org')));
        self::assertFalse($this->validate(ConnectionSeedCommand::buildConfig(self::URL, null, 56)));
    }

    public function testParsesSeedFileEntries(): void
    {
        $entries = ConnectionSeedCommand::parseEntries((string) json_encode([
            ['name' => ' default ', 'config' => ['base_url' => self::URL, 'api_key' => 'fc-secret']],
            ['name' => 'staging', 'config' => ['base_url' => self::URL, 'timeout' => 50, 'verify_ssl' => false]],
        ]));

        self::assertSame([
            ['name' => 'default', 'config' => ConnectionSeedCommand::buildConfig(self::URL, 'fc-secret')],
            ['name' => 'staging', 'config' => ConnectionSeedCommand::buildConfig(self::URL, null, 50, false)],
        ], $entries);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function malformedSeedFiles(): iterable
    {
        yield 'no JSON' => ['{', 'not valid JSON'];
        yield 'object instead of list' => ['{"name": "default"}', 'JSON list'];
        yield 'entry no object' => ['["default"]', 'Entry 0 must be an object'];
        yield 'empty name' => ['[{"name": " ", "config": {"base_url": "https://f.example.org"}}]', '"name" must be a non-empty string'];
        yield 'unknown entry key' => ['[{"name": "a", "type": "firecrawl", "config": {"base_url": "https://f.example.org"}}]', 'unknown keys type'];
        yield 'config missing' => ['[{"name": "a"}]', '"config" must be an object'];
        yield 'unknown config key' => ['[{"name": "a", "config": {"base_url": "https://f.example.org", "url": "x"}}]', 'unknown config keys url'];
        yield 'base_url missing' => ['[{"name": "a", "config": {}}]', '"base_url" must be a non-empty string'];
        yield 'api_key no string' => ['[{"name": "a", "config": {"base_url": "https://f.example.org", "api_key": 1}}]', '"api_key" must be a string'];
        yield 'timeout no integer' => ['[{"name": "a", "config": {"base_url": "https://f.example.org", "timeout": "50"}}]', '"timeout" must be an integer'];
        yield 'verify_ssl no boolean' => ['[{"name": "a", "config": {"base_url": "https://f.example.org", "verify_ssl": "no"}}]', '"verify_ssl" must be a boolean'];
    }

    #[DataProvider('malformedSeedFiles')]
    public function testMalformedSeedFilesAreRejected(string $json, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        ConnectionSeedCommand::parseEntries($json);
    }

    public function testRejectionsNeverEchoTheApiKey(): void
    {
        try {
            ConnectionSeedCommand::parseEntries('[{"name": "a", "config": {"base_url": "", "api_key": "fc-secret"}}]');
            self::fail('Expected an InvalidArgumentException.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringNotContainsString('fc-secret', $e->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    private function validate(array $config): bool
    {
        $path = (new FirecrawlExtension(new FakeFirecrawlClientFactory()))->getSchemaPath();
        $schema = json_decode((string) file_get_contents($path));

        return (new Validator())->validate(json_decode(json_encode($config, \JSON_THROW_ON_ERROR)), $schema)->isValid();
    }
}
