<?php

// file generated with AI assistance: Claude Code - 2026-11-06 16:00:00 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\Crawl;

use Dmstr\Firecrawl\Entity\CrawlProfile;
use Dmstr\SymfonyJobQueue\Entity\Job;
use Dmstr\SymfonyJobQueue\Service\JobQueueService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Queues the discover, the crawl or the import of a profile as a background job
 * (input `{"crawlProfile": "<uuid>"}`), run by
 * {@see \Dmstr\Firecrawl\JobProcessor\CrawlCommandJobProcessor}.
 *
 * One job per profile at a time, because each step reads what the one
 * before writes in `var/crawl/<profile>/`: the same step while it is
 * pending or running returns that job, another step is refused with 409.
 */
final class CrawlJobQueue
{
    public const string JOB_TYPE_DISCOVER = 'crawl_profile_discover';
    public const string JOB_TYPE_CRAWL = 'crawl_profile_crawl';
    public const string JOB_TYPE_IMPORT = 'crawl_profile_import';
    /** The command each job type runs. */
    public const array COMMANDS = [
        self::JOB_TYPE_DISCOVER => 'app:crawl:discover',
        self::JOB_TYPE_CRAWL => 'app:crawl:run',
        self::JOB_TYPE_IMPORT => 'app:crawl:import',
    ];

    public function __construct(
        private readonly JobQueueService $jobQueueService,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function queue(CrawlProfile $profile, string $type): Job
    {
        $input = ['crawlProfile' => $profile->getId()->toRfc4122()];

        $open = $this->openJob($input);
        if (null === $open) {
            return $this->jobQueueService->createAndDispatch($type, $input);
        }
        if ($open->getType() === $type) {
            return $open;
        }

        throw new ConflictHttpException(\sprintf('Crawl profile "%s" has a %s job that is %s (%s); wait until it is done.', $profile->getName(), self::COMMANDS[$open->getType()], $open->getStatus(), $open->getId()));
    }

    /**
     * A pending or running discover or crawl job of the profile.
     *
     * @param array<string, string> $input
     */
    private function openJob(array $input): ?Job
    {
        $jobs = $this->entityManager->getRepository(Job::class)->findBy(
            ['type' => array_keys(self::COMMANDS), 'status' => ['pending', 'running']],
            ['createdAt' => 'DESC'],
        );

        foreach ($jobs as $job) {
            if ($job instanceof Job && $job->getInputData() == $input) {
                return $job;
            }
        }

        return null;
    }
}
