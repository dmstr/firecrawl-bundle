<?php

// file generated with AI assistance: Claude Code - 2026-10-02 23:51:30 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\Tests\Unit\Connection;

use ApiPlatform\Metadata\Exception\ItemNotFoundException;
use ApiPlatform\Metadata\IriConverterInterface;
use Dmstr\ApiConfiguration\Entity\ApiConfiguration;
use Dmstr\ApiPlatformUtils\Service\UuidResolver;
use Dmstr\Firecrawl\Connection\FirecrawlConnectionResolver;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Kernel-free checks of the connection resolution with stubbed
 * collaborators: the implicit single active connection and explicit
 * references (name, UUID, IRI).
 */
final class FirecrawlConnectionResolverTest extends TestCase
{
    /** @var list<ApiConfiguration> */
    private array $stored = [];

    /** @var array<string, object> */
    private array $iris = [];

    private ?\Closure $uuidLookup = null;

    public function testNullSelectsTheSingleActiveConnection(): void
    {
        $default = self::connection('firecrawl', 'default');
        $this->stored = [$default, self::connection('firecrawl', 'old')->setActive(false), self::connection('mcp', 'docs')];

        self::assertSame($default, $this->resolver()->resolve(null));
        self::assertSame($default, $this->resolver()->resolve('  '));
    }

    public function testNoActiveConnectionIsRejected(): void
    {
        $this->stored = [self::connection('mcp', 'docs')];
        $this->expectRejection('No active firecrawl connection; create one under /api/admin/api_configurations or pass apiConfiguration.');

        $this->resolver()->resolve(null);
    }

    public function testOnlyInactiveConnectionsAreRejected(): void
    {
        $this->stored = [self::connection('firecrawl', 'default')->setActive(false)];
        $this->expectRejection('No active firecrawl connection');

        $this->resolver()->resolve(null);
    }

    public function testSeveralActiveConnectionsAreRejectedWithTheirNames(): void
    {
        $this->stored = [self::connection('firecrawl', 'staging'), self::connection('firecrawl', 'default')];
        $this->expectRejection('Several active firecrawl connections; pass apiConfiguration to choose one of: default, staging.');

        $this->resolver()->resolve('');
    }

    public function testResolvesByName(): void
    {
        $staging = self::connection('firecrawl', 'staging');
        $this->stored = [self::connection('firecrawl', 'default'), $staging, self::connection('mcp', 'staging')];

        self::assertSame($staging, $this->resolver()->resolve('staging'));
    }

    public function testResolvesByIri(): void
    {
        $default = self::connection('firecrawl', 'default');
        $this->iris['/api/admin/api_configurations/'.$default->getId()] = $default;

        self::assertSame($default, $this->resolver()->resolve('/api/admin/api_configurations/'.$default->getId()));
    }

    public function testResolvesByFullAndPartialUuid(): void
    {
        $default = self::connection('firecrawl', 'default');
        $this->uuidLookup = static fn (string $id): ?ApiConfiguration => str_starts_with((string) $default->getId(), $id) ? $default : null;

        self::assertSame($default, $this->resolver()->resolve((string) $default->getId()));
        self::assertSame($default, $this->resolver()->resolve(substr((string) $default->getId(), 0, 8)));
    }

    public function testUnknownNameIsRejected(): void
    {
        $this->expectRejection('Unknown Firecrawl connection "missing"');

        $this->resolver()->resolve('missing');
    }

    public function testInactiveConnectionIsRejected(): void
    {
        $this->stored = [self::connection('firecrawl', 'default')->setActive(false)];
        $this->expectRejection('is inactive');

        $this->resolver()->resolve('default');
    }

    public function testAmbiguousNameIsRejected(): void
    {
        $this->stored = [self::connection('firecrawl', 'default'), self::connection('firecrawl', 'default')];
        $this->expectRejection('is ambiguous');

        $this->resolver()->resolve('default');
    }

    public function testConnectionOfAnotherTypeByIriIsRejected(): void
    {
        $mcp = self::connection('mcp', 'docs');
        $this->iris['/api/admin/api_configurations/'.$mcp->getId()] = $mcp;
        $this->expectRejection('is not a Firecrawl connection');

        $this->resolver()->resolve('/api/admin/api_configurations/'.$mcp->getId());
    }

    public function testConnectionOfAnotherTypeByUuidIsRejected(): void
    {
        $mcp = self::connection('mcp', 'docs');
        $this->uuidLookup = static fn (): ApiConfiguration => $mcp;
        $this->expectRejection('is not a Firecrawl connection');

        $this->resolver()->resolve((string) $mcp->getId());
    }

    public function testIriOfAnotherResourceIsRejected(): void
    {
        $this->iris['/api/admin/collections/x'] = new \stdClass();
        $this->expectRejection('does not reference a connection');

        $this->resolver()->resolve('/api/admin/collections/x');
    }

    private function expectRejection(string $message): void
    {
        $this->expectException(UnprocessableEntityHttpException::class);
        $this->expectExceptionMessage($message);
    }

    private function resolver(): FirecrawlConnectionResolver
    {
        $repository = $this->createStub(EntityRepository::class);
        $repository->method('findBy')->willReturnCallback(fn (array $criteria, ?array $orderBy = null, ?int $limit = null): array => \array_slice(array_values(array_filter(
            $this->stored,
            static fn (ApiConfiguration $c): bool => $c->getType() === $criteria['type']
                && (!\array_key_exists('name', $criteria) || $c->getName() === $criteria['name'])
                && (!\array_key_exists('active', $criteria) || $c->isActive() === $criteria['active']),
        )), 0, $limit));

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);

        $iriConverter = $this->createStub(IriConverterInterface::class);
        $iriConverter->method('getResourceFromIri')->willReturnCallback(
            fn (string $iri): object => $this->iris[$iri] ?? throw new ItemNotFoundException('Not found'),
        );

        $uuidResolver = $this->createStub(UuidResolver::class);
        $uuidResolver->method('findByPartialUuid')->willReturnCallback(
            fn (string $class, string $id): ?object => null !== $this->uuidLookup ? ($this->uuidLookup)($id) : null,
        );

        return new FirecrawlConnectionResolver($entityManager, $iriConverter, $uuidResolver);
    }

    private static function connection(string $type, string $name): ApiConfiguration
    {
        return (new ApiConfiguration())
            ->setName($name)
            ->setActive(true)
            ->setConfigJson(['type' => $type, 'endpoint_type' => 'rest', 'base_url' => 'https://example.org']);
    }
}
