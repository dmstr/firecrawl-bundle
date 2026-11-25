<?php

// file generated with AI assistance: Claude Code - 2026-11-05 12:30:00 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use Dmstr\Firecrawl\Crawl\CrawlProfileSecrets;
use Dmstr\Firecrawl\Entity\CrawlProfile;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Writes a crawl profile, with a new login password encrypted first: the
 * password never reaches the database in clear.
 *
 * @implements ProcessorInterface<CrawlProfile, CrawlProfile>
 */
final readonly class CrawlProfileProcessor implements ProcessorInterface
{
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private ProcessorInterface $persistProcessor,
        private CrawlProfileSecrets $secrets,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        if ($data instanceof CrawlProfile) {
            $this->secrets->storeNewPassword($data);
        }

        return $this->persistProcessor->process($data, $operation, $uriVariables, $context);
    }
}
