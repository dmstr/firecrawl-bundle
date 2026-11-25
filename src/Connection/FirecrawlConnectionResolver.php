<?php

// file generated with AI assistance: Claude Code - 2026-10-02 23:51:30 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\Connection;

use ApiPlatform\Metadata\Exception\ExceptionInterface as ApiPlatformException;
use ApiPlatform\Metadata\IriConverterInterface;
use Dmstr\ApiConfiguration\Entity\ApiConfiguration;
use Dmstr\ApiPlatformUtils\Service\UuidResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Resolves the connection a Firecrawl operation runs against to an active
 * ApiConfiguration entity of type `firecrawl`.
 *
 * A non-empty reference is one of:
 *  - an IRI (`/api/admin/api_configurations/<uuid>`), via IriConverterInterface;
 *  - a full or partial UUID (at least 8 hex digits), via UuidResolver;
 *  - a connection name, looked up within `type = 'firecrawl'` only, because
 *    names are not unique across connection types.
 * A UUID-like reference that matches no firecrawl connection by id is
 * retried as a name.
 *
 * A null or blank reference selects the single active `firecrawl`
 * connection.
 *
 * Unknown, inactive, ambiguous and non-`firecrawl` references, no active
 * connection and several active connections raise an
 * UnprocessableEntityHttpException (HTTP 422).
 */
final class FirecrawlConnectionResolver
{
    private const string UUID_LIKE = '/^[0-9a-f]{8}[0-9a-f-]{0,28}$/i';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly IriConverterInterface $iriConverter,
        private readonly UuidResolver $uuidResolver,
    ) {
    }

    /**
     * @param string|null $reference IRI, (partial) UUID or name; null or blank
     *                               selects the single active firecrawl connection
     *
     * @throws UnprocessableEntityHttpException with one of the messages
     *                                          - No active firecrawl connection; create one under /api/admin/api_configurations or pass apiConfiguration.
     *                                          - Several active firecrawl connections; pass apiConfiguration to choose one of: <name>, <name>.
     *                                          - Unknown Firecrawl connection "<ref>".
     *                                          - Firecrawl connection "<ref>" is inactive.
     *                                          - Firecrawl connection reference "<ref>" is ambiguous. / Firecrawl connection name "<ref>" is ambiguous.
     *                                          - Connection "<ref>" is not a Firecrawl connection.
     *                                          - "<iri>" does not reference a connection.
     */
    public function resolve(?string $reference): ApiConfiguration
    {
        $reference = trim((string) $reference);
        if ('' === $reference) {
            return $this->implicit();
        }

        if (str_starts_with($reference, '/')) {
            return $this->assertUsable($this->fromIri($reference), $reference);
        }

        $byId = null;
        if (1 === preg_match(self::UUID_LIKE, $reference)) {
            try {
                $byId = $this->uuidResolver->findByPartialUuid(ApiConfiguration::class, $reference);
            } catch (\RuntimeException) {
                throw new UnprocessableEntityHttpException(\sprintf('Firecrawl connection reference "%s" is ambiguous.', $reference));
            }

            if ($byId instanceof ApiConfiguration && self::isFirecrawl($byId)) {
                return $this->assertUsable($byId, $reference);
            }
        }

        $byName = $this->findByName($reference);
        if (null !== $byName) {
            return $this->assertUsable($byName, $reference);
        }

        if ($byId instanceof ApiConfiguration) {
            // Matched a connection of another type by id.
            return $this->assertUsable($byId, $reference);
        }

        throw new UnprocessableEntityHttpException(\sprintf('Unknown Firecrawl connection "%s".', $reference));
    }

    private function implicit(): ApiConfiguration
    {
        $active = $this->entityManager->getRepository(ApiConfiguration::class)
            ->findBy(['type' => FirecrawlExtension::NAME, 'active' => true]);

        if ([] === $active) {
            throw new UnprocessableEntityHttpException('No active firecrawl connection; create one under /api/admin/api_configurations or pass apiConfiguration.');
        }
        if (\count($active) > 1) {
            $names = array_map(static fn (ApiConfiguration $c): string => $c->getName(), $active);
            sort($names);

            throw new UnprocessableEntityHttpException(\sprintf('Several active firecrawl connections; pass apiConfiguration to choose one of: %s.', implode(', ', $names)));
        }

        return $this->assertUsable($active[0], $active[0]->getName());
    }

    private function fromIri(string $iri): ApiConfiguration
    {
        try {
            $resource = $this->iriConverter->getResourceFromIri($iri);
        } catch (ApiPlatformException) {
            throw new UnprocessableEntityHttpException(\sprintf('Unknown Firecrawl connection "%s".', $iri));
        }

        if (!$resource instanceof ApiConfiguration) {
            throw new UnprocessableEntityHttpException(\sprintf('"%s" does not reference a connection.', $iri));
        }

        return $resource;
    }

    private function findByName(string $name): ?ApiConfiguration
    {
        $matches = $this->entityManager->getRepository(ApiConfiguration::class)
            ->findBy(['type' => FirecrawlExtension::NAME, 'name' => $name], null, 2);

        if (\count($matches) > 1) {
            throw new UnprocessableEntityHttpException(\sprintf('Firecrawl connection name "%s" is ambiguous.', $name));
        }

        return $matches[0] ?? null;
    }

    private function assertUsable(ApiConfiguration $connection, string $reference): ApiConfiguration
    {
        if (!self::isFirecrawl($connection)) {
            throw new UnprocessableEntityHttpException(\sprintf('Connection "%s" is not a Firecrawl connection.', $reference));
        }
        if (!$connection->isActive()) {
            throw new UnprocessableEntityHttpException(\sprintf('Firecrawl connection "%s" is inactive.', $reference));
        }

        return $connection;
    }

    private static function isFirecrawl(ApiConfiguration $connection): bool
    {
        return FirecrawlExtension::NAME === ($connection->getConfigJson()['type'] ?? null);
    }
}
