<?php

// file generated with AI assistance: Claude Code - 2026-10-03 00:10:23 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\Command;

use Dmstr\ApiPlatformUtils\Service\UuidResolver;
use Dmstr\Firecrawl\Entity\Refs\FirecrawlJob;
use Dmstr\Firecrawl\Jobs\JobFormatter;
use Dmstr\Firecrawl\Jobs\JobSyncer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Console twin of `POST /api/refs/firecrawl_jobs/{id}/sync`: refreshes one
 * row through {@see JobSyncer} and prints it as JSON. Exits non-zero when
 * the row is not found, the id is ambiguous or the remote call failed (the
 * row then carries `errorMessage`).
 */
#[AsCommand(name: 'firecrawl:jobs:sync', description: 'Refresh a Firecrawl job reference from its instance')]
final class JobsSyncCommand extends Command
{
    public function __construct(
        private readonly JobSyncer $jobSyncer,
        private readonly UuidResolver $uuidResolver,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('id', InputArgument::REQUIRED, 'Full or partial UUID of the job reference');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $id = trim((string) $input->getArgument('id'));

        try {
            $job = '' !== $id ? $this->uuidResolver->findByPartialUuid(FirecrawlJob::class, $id) : null;
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
        if (!$job instanceof FirecrawlJob) {
            $io->error(\sprintf('Firecrawl job "%s" not found.', $id));

            return Command::FAILURE;
        }

        if (null === $job->getFirecrawlId()) {
            $output->writeln(JobFormatter::toJson($job));
            $io->note('The job has no remote id (scrape or failed start); nothing to sync.');

            return Command::SUCCESS;
        }

        $this->jobSyncer->sync($job);
        $output->writeln(JobFormatter::toJson($job));

        if (null !== $job->getErrorMessage()) {
            $io->error($job->getErrorMessage());

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
