<?php

// file generated with AI assistance: Claude Code - 2026-10-03 00:09:10 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\Tests\Unit\Passthru;

use ApiPlatform\Metadata\IriConverterInterface;
use Dmstr\ApiConfiguration\Entity\ApiConfiguration;
use Dmstr\ApiPlatformUtils\Service\UuidResolver;
use Dmstr\Firecrawl\Connection\FirecrawlConnectionResolver;
use Dmstr\Firecrawl\Passthru\PassthruService;
use Dmstr\Firecrawl\State\InputBodyValidator;
use Dmstr\Firecrawl\Testing\FakeFirecrawlClientFactory;
use Dmstr\Firecrawl\Tests\Fixtures\FirecrawlSecrets;
use Dmstr\OpenApiJsonSchema\Service\OperationInputSchemaResolver;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Psr\Log\AbstractLogger;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Kernel-free fixtures of the pass-through tests: a real PassthruService
 * over the bundle's input schemas (config/schemas), a
 * FirecrawlConnectionResolver whose Doctrine collaborators answer from
 * $stored, the fake client factory and an in-memory logger. Call
 * setUpPassthru() in setUp().
 */
trait PassthruFixturesTrait
{
    private const string DEFAULT_URL = 'https://fc-default.example.org';
    private const string STAGING_URL = 'https://fc-staging.example.org';

    /** @var list<ApiConfiguration> */
    private array $stored = [];

    private FakeFirecrawlClientFactory $fake;

    /** Anonymous logger with a public `records` list of [level, message, context]. */
    private AbstractLogger $logger;

    private function setUpPassthru(): void
    {
        $this->stored = [];
        $this->fake = new FakeFirecrawlClientFactory();
        $this->logger = new class extends AbstractLogger {
            /** @var list<array{level: mixed, message: string, context: array<mixed>}> */
            public array $records = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
            }
        };
    }

    private function service(?Security $security = null): PassthruService
    {
        $bundle = \dirname(__DIR__, 3);
        $validator = new InputBodyValidator(new OperationInputSchemaResolver([$bundle.'/config/schemas']));

        return new PassthruService($validator, $this->resolver(), $this->fake, FirecrawlSecrets::create(), $this->logger, $security);
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

        $uuidResolver = $this->createStub(UuidResolver::class);
        $uuidResolver->method('findByPartialUuid')->willReturnCallback(function (string $class, string $id): ?ApiConfiguration {
            foreach ($this->stored as $connection) {
                if (str_starts_with((string) $connection->getId(), $id)) {
                    return $connection;
                }
            }

            return null;
        });

        return new FirecrawlConnectionResolver($entityManager, $this->createStub(IriConverterInterface::class), $uuidResolver);
    }

    private static function connection(string $name, string $baseUrl, bool $active = true, ?string $apiKey = null): ApiConfiguration
    {
        $config = ['firecrawl' => true, 'type' => 'firecrawl', 'endpoint_type' => 'rest', 'base_url' => $baseUrl];
        if (null !== $apiKey) {
            $config['api_key'] = $apiKey;
        }

        return (new ApiConfiguration())->setName($name)->setActive($active)->setConfigJson($config);
    }
}
