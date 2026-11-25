<?php

// file generated with AI assistance: Claude Code - 2026-10-19 13:49:00 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\Crawl;

use Dmstr\ApiConfiguration\Security\ConfigSecrets;
use Dmstr\Firecrawl\Client\FirecrawlClientFactoryInterface;
use Dmstr\Firecrawl\Client\FirecrawlClientInterface;
use Dmstr\Firecrawl\Connection\FirecrawlConnectionResolver;

/**
 * What both crawl commands need before the first request: a client for the
 * Firecrawl connection and, if the profile has an `auth` block, the session
 * cookie from {@see FormLogin}. The cookie is returned to the caller and not
 * stored anywhere.
 */
final class CrawlSessionOpener
{
    public function __construct(
        private readonly FirecrawlConnectionResolver $connectionResolver,
        private readonly FirecrawlClientFactoryInterface $clientFactory,
        private readonly ConfigSecrets $secrets,
        private readonly FormLogin $login,
    ) {
    }

    /**
     * @return array{0: FirecrawlClientInterface, 1: ?string} the client and the `Cookie` header value (null without login)
     *
     * @throws \RuntimeException when the login fails
     */
    public function open(CrawlProfile $profile, ?string $connection, bool $login = true): array
    {
        $cookie = null !== $profile->auth && $login ? $this->login->login($profile) : null;

        $resolved = $this->connectionResolver->resolve($connection);

        return [$this->clientFactory->create($this->secrets->decrypt($resolved->getConfigJson())), $cookie];
    }
}
