<?php

// file generated with AI assistance: Claude Code - 2026-11-06 16:00:00 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\JobProcessor;

use Dmstr\Firecrawl\Command\CrawlDiscoverCommand;
use Dmstr\Firecrawl\Command\CrawlImportCommand;
use Dmstr\Firecrawl\Command\CrawlRunCommand;
use Dmstr\Firecrawl\Crawl\CrawlJobQueue;
use Dmstr\Firecrawl\Entity\CrawlProfile;
use Dmstr\SymfonyJobQueue\Entity\Job;
use Dmstr\SymfonyJobQueue\Service\Job\JobProcessorInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Uid\Uuid;

/**
 * Runs the discover, the crawl or the import of a profile for the jobs
 * queued by {@see CrawlJobQueue}: the same command as on the shell
 * (`app:crawl:discover`, `app:crawl:run`, `app:crawl:import <name>`), so
 * both ways do the same. The command's output becomes the job's `resultData.output`.
 *
 * A failed command fails the job without a messenger retry: a crawl takes
 * minutes, and a wrong login or a missing urls.json fails again the same
 * way. Start it again from the profile after the fix.
 */
final class CrawlCommandJobProcessor implements JobProcessorInterface
{
    /** Lines of command output kept in the job. */
    private const int OUTPUT_LINES = 200;

    public function __construct(
        private readonly CrawlDiscoverCommand $discover,
        private readonly CrawlRunCommand $crawl,
        private readonly CrawlImportCommand $import,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function supports(string $jobType): bool
    {
        return isset(CrawlJobQueue::COMMANDS[$jobType]);
    }

    public function process(Job $job): void
    {
        $id = (string) ($job->getInputData()['crawlProfile'] ?? '');
        $profile = Uuid::isValid($id) ? $this->entityManager->find(CrawlProfile::class, Uuid::fromString($id)) : null;
        if (!$profile instanceof CrawlProfile) {
            throw new UnrecoverableMessageHandlingException(\sprintf('Crawl profile "%s" does not exist (anymore).', $id));
        }

        $command = match ($job->getType()) {
            CrawlJobQueue::JOB_TYPE_DISCOVER => $this->discover,
            CrawlJobQueue::JOB_TYPE_CRAWL => $this->crawl,
            default => $this->import,
        };
        $output = new BufferedOutput();
        $exitCode = $command->run(new ArrayInput(['profile' => $profile->getName()]), $output);

        $lines = explode("\n", trim($output->fetch()));
        $job->setResultData([
            'profile' => $profile->getName(),
            'command' => CrawlJobQueue::COMMANDS[$job->getType()],
            'output' => implode("\n", \array_slice($lines, -self::OUTPUT_LINES)),
        ]);

        if (Command::SUCCESS !== $exitCode) {
            throw new UnrecoverableMessageHandlingException(\sprintf('%s %s failed: %s', CrawlJobQueue::COMMANDS[$job->getType()], $profile->getName(), self::error($lines)));
        }
    }

    /**
     * The error block of SymfonyStyle (`[ERROR] …`), or the last line.
     *
     * @param list<string> $lines
     */
    private static function error(array $lines): string
    {
        $text = implode(' ', array_map('trim', $lines));
        if (1 === preg_match('/\[ERROR\]\s*(.+)$/', $text, $match)) {
            return trim($match[1]);
        }

        return trim((string) end($lines));
    }
}
