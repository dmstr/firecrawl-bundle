<?php

// file generated with AI assistance: Claude Code - 2026-10-03 00:10:23 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\Tests\Unit\Jobs;

use ApiPlatform\Metadata\Exception\ItemNotFoundException;
use ApiPlatform\Metadata\IriConverterInterface;
use Dmstr\ApiConfiguration\Entity\ApiConfiguration;
use Dmstr\ApiPlatformUtils\Service\UuidResolver;
use Dmstr\Firecrawl\Connection\FirecrawlConnectionResolver;
use Dmstr\Firecrawl\Entity\Refs\FirecrawlJob;
use Dmstr\Firecrawl\State\InputBodyValidator;
use Dmstr\OpenApiJsonSchema\Service\ChainInputSchemaResolver;
use Dmstr\OpenApiJsonSchema\Service\OperationInputSchemaResolver;
use Dmstr\SymfonyJobQueue\Entity\Job;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;

/**
 * Kernel-free stand-in for the entity manager of the job reference tests:
 * connections, job reference rows and queue jobs live in arrays, persist()
 * adds immediately, flush() is only counted. findBy() on the job reference
 * repository supports scalar, entity and list (IN) criteria. Also builds
 * the real connection resolver (IRIs from `$iris`, no partial UUIDs) and an
 * input-body validator on the bundle's entity schemas, resolved like the
 * bundle's resolver instance does.
 */
trait InMemoryJobsTrait
{
    /** @var list<ApiConfiguration> */
    private array $connections = [];

    /** @var array<int, FirecrawlJob> keyed by spl_object_id() */
    private array $rows = [];

    /** @var list<Job> */
    private array $jobs = [];

    private int $flushes = 0;

    /** @var array<string, object> IRI => resource for the stubbed IriConverter */
    private array $iris = [];

    private function entityManager(): EntityManagerInterface
    {
        $rowRepository = $this->createStub(EntityRepository::class);
        $rowRepository->method('findBy')->willReturnCallback(function (array $criteria, ?array $orderBy = null, ?int $limit = null): array {
            $matches = array_values(array_filter($this->rows, static function (FirecrawlJob $row) use ($criteria): bool {
                foreach ($criteria as $field => $expected) {
                    $actual = match ($field) {
                        'apiConfiguration' => $row->getApiConfiguration(),
                        'firecrawlId' => $row->getFirecrawlId(),
                        'status' => $row->getStatus(),
                        'type' => $row->getType(),
                        default => throw new \LogicException('Unexpected criterion '.$field),
                    };
                    if (\is_array($expected) ? !\in_array($actual, $expected, true) : $actual !== $expected) {
                        return false;
                    }
                }

                return true;
            }));

            return \array_slice($matches, 0, $limit);
        });

        $connectionRepository = $this->createStub(EntityRepository::class);
        $connectionRepository->method('findBy')->willReturnCallback(function (array $criteria, ?array $orderBy = null, ?int $limit = null): array {
            $matches = array_values(array_filter(
                $this->connections,
                static fn (ApiConfiguration $c): bool => $c->getType() === $criteria['type']
                    && (!isset($criteria['name']) || $c->getName() === $criteria['name'])
                    && (!isset($criteria['active']) || $c->isActive() === $criteria['active']),
            ));
            usort($matches, static fn (ApiConfiguration $a, ApiConfiguration $b): int => strcmp($a->getName(), $b->getName()));

            return \array_slice($matches, 0, $limit);
        });

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturnCallback(static fn (string $class): EntityRepository => match ($class) {
            FirecrawlJob::class => $rowRepository,
            ApiConfiguration::class => $connectionRepository,
            default => throw new \LogicException('Unexpected repository '.$class),
        });
        $entityManager->method('persist')->willReturnCallback(function (object $object): void {
            if ($object instanceof FirecrawlJob) {
                $this->rows[spl_object_id($object)] = $object;
            } elseif ($object instanceof Job) {
                $this->jobs[] = $object;
            }
        });
        $entityManager->method('flush')->willReturnCallback(function (): void {
            ++$this->flushes;
        });
        $entityManager->method('find')->willReturnCallback(function (string $class, mixed $id): ?object {
            $candidates = ApiConfiguration::class === $class ? $this->connections : array_values($this->rows);
            foreach ($candidates as $candidate) {
                if ((string) $candidate->getId() === (string) $id) {
                    return $candidate;
                }
            }

            return null;
        });

        return $entityManager;
    }

    private function addConnection(string $name, string $baseUrl, bool $active = true, string $type = 'firecrawl'): ApiConfiguration
    {
        $config = 'firecrawl' === $type
            ? ['firecrawl' => true, 'type' => 'firecrawl', 'endpoint_type' => 'rest', 'base_url' => $baseUrl]
            : ['mcp' => true, 'type' => $type, 'endpoint_type' => 'rest', 'url' => $baseUrl, 'transport' => 'streamable-http'];
        $connection = (new ApiConfiguration())
            ->setName($name)
            ->setConfigJson($config)
            ->setActive($active);
        $this->connections[] = $connection;

        return $connection;
    }

    private function addRow(ApiConfiguration $connection, ?string $firecrawlId, string $status = FirecrawlJob::STATUS_SCRAPING, string $url = 'https://example.org'): FirecrawlJob
    {
        $row = (new FirecrawlJob($connection))
            ->setUrl($url)
            ->setFirecrawlId($firecrawlId)
            ->setStatus($status);
        $this->rows[spl_object_id($row)] = $row;

        return $row;
    }

    /**
     * @return array<string, FirecrawlJob> the connection's rows by remote id
     */
    private function rowsByFirecrawlId(ApiConfiguration $connection): array
    {
        $byId = [];
        foreach ($this->rows as $row) {
            if ($row->getApiConfiguration() === $connection) {
                $byId[(string) $row->getFirecrawlId()] = $row;
            }
        }
        ksort($byId);

        return $byId;
    }

    private function connectionResolver(): FirecrawlConnectionResolver
    {
        $iriConverter = $this->createStub(IriConverterInterface::class);
        $iriConverter->method('getResourceFromIri')->willReturnCallback(
            fn (string $iri): object => $this->iris[$iri] ?? throw new ItemNotFoundException('Not found'),
        );
        $uuidResolver = $this->createStub(UuidResolver::class);
        $uuidResolver->method('findByPartialUuid')->willReturnCallback(function (string $class, string $id): ?object {
            foreach ($this->connections as $connection) {
                if ((string) $connection->getId() === $id) {
                    return $connection;
                }
            }

            return null;
        });

        return new FirecrawlConnectionResolver($this->entityManager(), $iriConverter, $uuidResolver);
    }

    private static function inputBodyValidator(): InputBodyValidator
    {
        $bundle = \dirname(__DIR__, 3);

        return new InputBodyValidator(new ChainInputSchemaResolver([
            new OperationInputSchemaResolver([$bundle.'/config/schemas'], [[$bundle.'/src/Entity', 'Dmstr\\Firecrawl\\Entity']]),
        ]));
    }
}
