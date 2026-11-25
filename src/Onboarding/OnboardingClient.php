<?php

// file generated with AI assistance: Claude Code - 2026-10-13 21:47:18 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\Onboarding;

use Dmstr\ApiConfiguration\Security\ConfigSecrets;
use Dmstr\Firecrawl\Client\FirecrawlApiException;
use Dmstr\Firecrawl\Client\FirecrawlClientFactoryInterface;
use Dmstr\Firecrawl\Client\FirecrawlClientInterface;
use Dmstr\Firecrawl\Connection\FirecrawlConnectionResolver;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Runs one Firecrawl call of an onboarding tool: resolves the connection
 * (422 when unusable), creates the client with the decrypted secrets, logs
 * one info line to the `firecrawl` channel (tool, connection, URLs, acting
 * user, outcome) and turns a Firecrawl failure into
 * {@see OnboardingFailure}, which the tools answer as a regular result
 * with `status: failed`: a model handles a result that says what went
 * wrong more reliably than a tool error (Claude Sonnet 5.5 sometimes ended
 * the turn without any answer after a tool error). An unusable connection
 * stays an error (422).
 */
final readonly class OnboardingClient
{
    public function __construct(
        private FirecrawlConnectionResolver $connectionResolver,
        private FirecrawlClientFactoryInterface $clientFactory,
        private ConfigSecrets $secrets,
        private LoggerInterface $firecrawlLogger,
        private ?Security $security = null,
    ) {
    }

    /**
     * @template T
     *
     * @param \Closure(FirecrawlClientInterface): T $call
     * @param list<string>                          $urls for the log line
     *
     * @return T
     *
     * @throws OnboardingFailure when Firecrawl fails
     */
    public function run(string $tool, ?string $connectionReference, array $urls, \Closure $call): mixed
    {
        $reference = trim((string) $connectionReference);
        $connection = $this->connectionResolver->resolve('' === $reference ? null : $reference);
        $client = $this->clientFactory->create($this->secrets->decrypt($connection->getConfigJson()));

        $logContext = [
            'operation' => $tool,
            'connection_id' => (string) $connection->getId(),
            'connection_name' => $connection->getName(),
            'urls' => $urls,
            'user' => $this->security?->getUser()?->getUserIdentifier() ?? 'cli',
        ];

        try {
            $result = $call($client);
        } catch (FirecrawlApiException $e) {
            $this->firecrawlLogger->info('Firecrawl {operation} via {connection_name} failed', $logContext + ['outcome' => 'error', 'status' => $e->getStatusCode()]);

            throw new OnboardingFailure(\sprintf('Firecrawl %s failed%s.', $tool, null === $e->getStatusCode() ? '' : \sprintf(' with HTTP %d', $e->getStatusCode())), $e);
        }

        $this->firecrawlLogger->info('Firecrawl {operation} via {connection_name}', $logContext + ['outcome' => 'ok']);

        return $result;
    }
}
