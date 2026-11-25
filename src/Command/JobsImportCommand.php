<?php

// file generated with AI assistance: Claude Code - 2026-10-03 07:35:09 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\Command;

use Dmstr\ApiPlatformUtils\Service\UuidResolver;
use Dmstr\Firecrawl\Entity\Refs\FirecrawlJob;
use Dmstr\Firecrawl\Import\ImportFailedException;
use Dmstr\Firecrawl\Import\JobImporter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Console twin of `POST /api/refs/firecrawl_jobs/{id}/import`, run
 * synchronously without a queue job: imports the result of a completed
 * crawl or batch scrape into a knowledge collection through
 * {@see JobImporter} and prints the report as JSON. The same checks as the
 * API apply (job type, status, `--delete-vanished` on a batch scrape,
 * collection). Exits non-zero when the job reference is not found, a check
 * fails or the import fails (the partial report is printed all the same).
 */
#[AsCommand(name: 'firecrawl:jobs:import', description: 'Import the result of a completed Firecrawl crawl or batch scrape into a knowledge collection')]
final class JobsImportCommand extends Command
{
    public function __construct(
        private readonly JobImporter $jobImporter,
        private readonly UuidResolver $uuidResolver,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('id', InputArgument::REQUIRED, 'Full or partial UUID of the job reference')
            ->addArgument('collection', InputArgument::REQUIRED, 'Target knowledge collection (UUID, IRI or name)')
            ->addOption('delete-vanished', null, InputOption::VALUE_NONE, 'Crawls only: remove documents of the same site whose page is no longer in the crawl (only after a complete import without failed pages)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $id = trim((string) $input->getArgument('id'));
        $deleteVanished = (bool) $input->getOption('delete-vanished');

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

        try {
            $this->jobImporter->assertImportable($job, $deleteVanished);
            $target = $this->jobImporter->prepare($job, (string) $input->getArgument('collection'));
        } catch (HttpExceptionInterface $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        try {
            $report = $this->jobImporter->import($job, $target, $deleteVanished);
        } catch (ImportFailedException $e) {
            $output->writeln(self::json($e->getReport()));
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $output->writeln(self::json($report));
        if ($report['failed'] > 0) {
            $io->warning(\sprintf('%d of %d page(s) failed.', $report['failed'], $report['pages']));
        }

        return Command::SUCCESS;
    }

    /**
     * @param array<string, mixed> $report
     */
    private static function json(array $report): string
    {
        return json_encode($report, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
    }
}
