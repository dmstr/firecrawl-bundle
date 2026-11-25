<?php

// file generated with AI assistance: Claude Code - 2026-11-05 12:30:00 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\Crawl;

use Dmstr\ApiPlatformUtils\Service\CredentialEncryption;
use Dmstr\Firecrawl\Entity\CrawlProfile as StoredProfile;

/**
 * The login password of a stored crawl profile, encrypted with the key of the
 * connection secrets (`CREDENTIALS_ENCRYPTION_KEY`).
 */
final readonly class CrawlProfileSecrets
{
    public function __construct(private CredentialEncryption $encryption)
    {
    }

    /** Encrypts a password set through the API or the import, if there is one. */
    public function storeNewPassword(StoredProfile $profile): void
    {
        $password = $profile->takeNewPassword();
        if (null !== $password) {
            $profile->setPasswordEncrypted($this->encryption->encrypt(['password' => $password]));
        }
    }

    public function password(StoredProfile $profile): ?string
    {
        $encrypted = $profile->getPasswordEncrypted();

        return null === $encrypted ? null : (string) ($this->encryption->decrypt($encrypted)['password'] ?? '');
    }
}
