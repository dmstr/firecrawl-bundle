<?php

// file generated with AI assistance: Claude Code - 2026-10-14 01:40:00 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\Onboarding;

use Psr\Cache\CacheItemPoolInterface;

/**
 * The boilerplate form of a sample job, kept on the server so the answer
 * tool can put it into the answer itself.
 *
 * A model asked to copy the form unchanged did not: it dropped a property
 * from `schema.properties` (while `required` and `additionalProperties:
 * false` stayed, so the form could not be submitted) and changed the
 * preselection. {@see \Dmstr\Firecrawl\State\Onboarding\SampleAnalyzeProcessor}
 * stores the form by job id, {@see \Dmstr\Firecrawl\State\Onboarding\ReplyProcessor}
 * takes it from here. Kept as long as Firecrawl keeps the job result (24 h).
 */
final readonly class FormStore
{
    public const int TTL_SECONDS = 86400;

    public function __construct(private CacheItemPoolInterface $cache)
    {
    }

    /**
     * @param array{id: string, schema: array<string, mixed>, value: array<string, mixed>} $form
     */
    public function save(string $jobId, array $form): void
    {
        $item = $this->cache->getItem(self::key($jobId));
        $item->set($form)->expiresAfter(self::TTL_SECONDS);
        $this->cache->save($item);
    }

    /**
     * @return array{id: string, schema: array<string, mixed>, value: array<string, mixed>}|null
     */
    public function find(string $jobId): ?array
    {
        $item = $this->cache->getItem(self::key($jobId));
        $form = $item->isHit() ? $item->get() : null;

        return \is_array($form) ? $form : null;
    }

    private static function key(string $jobId): string
    {
        return 'crawl_onboarding_form.'.hash('xxh128', $jobId);
    }
}
