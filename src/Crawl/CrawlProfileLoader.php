<?php

// file generated with AI assistance: Claude Code - 2026-11-05 12:30:00 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\Crawl;

use Dmstr\Firecrawl\Entity\CrawlProfile as StoredProfile;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Loads a stored crawl profile by name for the crawl commands: the runtime
 * profile with the decrypted password in `auth.password`, and the stored one
 * for its connections.
 */
final readonly class CrawlProfileLoader
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private CrawlProfileSecrets $secrets,
    ) {
    }

    /**
     * @return array{0: CrawlProfile, 1: StoredProfile}
     *
     * @throws \InvalidArgumentException when there is no active profile of that name, or it is invalid
     */
    public function load(string $name): array
    {
        $stored = $this->entityManager->getRepository(StoredProfile::class)->findOneBy(['name' => $name]);
        if (null === $stored || !$stored->isActive()) {
            throw new \InvalidArgumentException(\sprintf('No active crawl profile "%s"; create it under /api/admin/crawl_profiles.', $name));
        }

        $config = $stored->toProfileData();
        if (\is_array($config['auth'] ?? null)) {
            $config['auth']['password'] = $this->secrets->password($stored);
        }

        return [CrawlProfile::fromArray($name, $config), $stored];
    }
}
