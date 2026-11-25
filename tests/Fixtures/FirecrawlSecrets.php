<?php

// file generated with AI assistance: Claude Code - 2026-10-05 18:50:29 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\Tests\Fixtures;

use Dmstr\ApiConfiguration\Security\ConfigSecrets;
use Dmstr\ApiConfiguration\Security\SecretSchemaResolver;
use Dmstr\ApiConfiguration\Service\ApiExtensionRegistry;
use Dmstr\ApiPlatformUtils\Service\CredentialEncryption;
use Dmstr\Firecrawl\Connection\FirecrawlExtension;
use Dmstr\Firecrawl\Testing\FakeFirecrawlClientFactory;
use Dmstr\OpenApiJsonSchema\Service\SchemaRegistry;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * A real ConfigSecrets for the kernel-free tests: knows the `firecrawl`
 * schema (secret `api_key`) and uses {@see self::INSECURE_TEST_KEY}, the
 * same key the test kernel configures.
 */
final class FirecrawlSecrets
{
    /** INSECURE, PUBLICLY KNOWN TEST KEY (32 bytes); tests only. */
    public const string INSECURE_TEST_KEY = 'firecrawl-bundle-INSECURE-key!!!';

    private function __construct()
    {
    }

    public static function create(): ConfigSecrets
    {
        $registry = new SchemaRegistry(new ArrayAdapter(), new NullLogger());
        $registry->register(new FirecrawlExtension(new FakeFirecrawlClientFactory()));

        return new ConfigSecrets(
            new SecretSchemaResolver($registry, new ApiExtensionRegistry()),
            static fn (): CredentialEncryption => new CredentialEncryption(self::INSECURE_TEST_KEY),
        );
    }
}

// - revised 2026-11-25
