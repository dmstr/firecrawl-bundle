<?php

// file generated with AI assistance: Claude Code - 2026-10-03 00:10:23 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\Command;

use Dmstr\Firecrawl\Connection\FirecrawlConnectionResolver;
use Dmstr\Firecrawl\Jobs\JobScanner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Console twin of `POST /api/refs/firecrawl_jobs/scan`, run synchronously
 * without a job: mirrors the active crawls of all `firecrawl` connections,
 * or of the one given with --connection (UUID, partial UUID, IRI or name),
 * into the job reference table and prints the summary. Exits non-zero when
 * every scanned connection failed (the same rule that fails the scan job);
 * a partial failure is reported as a warning.
 */
#[AsCommand(name: 'firecrawl:jobs:scan', description: 'Mirror the active crawls of Firecrawl connections into the job reference table')]
final class JobsScanCommand extends Command
{
    public function __construct(
        private readonly JobScanner $scanner,
        private readonly FirecrawlConnectionResolver $connectionResolver,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('connection', 'c', InputOption::VALUE_REQUIRED, 'Scan only this active firecrawl connection (UUID, partial UUID, IRI or name)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $reference = $input->getOption('connection');

        if (\is_string($reference) && '' !== $reference) {
            try {
                $connection = $this->connectionResolver->resolve($reference);
            } catch (UnprocessableEntityHttpException $e) {
                $io->error($e->getMessage());

                return Command::FAILURE;
            }
            $summary = $this->scanner->scan([$connection]);
        } else {
            $summary = $this->scanner->scanAll();
        }

        if ([] === $summary['connections']) {
            $io->note('No firecrawl connections found.');

            return Command::SUCCESS;
        }

        $rows = [];
        foreach ($summary['connections'] as $connection) {
            $rows[] = [
                $connection['name'],
                $connection['status'],
                $connection['created'],
                $connection['unchanged'],
                $connection['error'] ?? implode("\n", $connection['warnings']),
            ];
        }
        $io->table(['Connection', 'Status', 'Created', 'Unchanged', 'Error / warnings'], $rows);

        $io->writeln(\sprintf(
            'Created %d, unchanged %d; %d connection(s) scanned, %d skipped, %d failed.',
            $summary['created'],
            $summary['unchanged'],
            $summary['scanned'],
            $summary['skipped'],
            $summary['failed'],
        ));

        if ($summary['failed'] > 0 && 0 === $summary['scanned']) {
            $io->error('Every scanned connection failed.');

            return Command::FAILURE;
        }
        if ($summary['failed'] > 0) {
            $io->warning(\sprintf('%d connection(s) failed.', $summary['failed']));
        }

        return Command::SUCCESS;
    }
}
