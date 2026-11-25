<?php

// file generated with AI assistance: Claude Code - 2026-11-06 14:00:00 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\Command;

use Dmstr\Firecrawl\Crawl\CrawlImporter;
use Dmstr\Firecrawl\Crawl\CrawlProfileLoader;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Phase 3 of a crawl profile: puts the Markdown files of the latest
 * `app:crawl:run` into the collection of the profile's `import` section,
 * with its chunk sizes ({@see CrawlImporter}). Fails when every page
 * failed; single failures are listed and keep the job completed.
 */
#[AsCommand(name: 'app:crawl:import', description: 'Import the Markdown files of the latest crawl into the collection of the profile\'s import section')]
final class CrawlImportCommand extends Command
{
    public function __construct(
        private readonly CrawlProfileLoader $profiles,
        private readonly CrawlImporter $importer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('profile', InputArgument::REQUIRED, 'Name of a crawl profile (/api/admin/crawl_profiles)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            [, $stored] = $this->profiles->load((string) $input->getArgument('profile'));
            $report = $this->importer->import($stored, static fn (string $line) => $io->writeln('  '.$line, OutputInterface::VERBOSITY_VERBOSE));
        } catch (\RuntimeException|\InvalidArgumentException|UnprocessableEntityHttpException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        foreach ($report['failures'] as $failure) {
            $io->warning(\sprintf('Failed %s: %s', $failure['url'], $failure['reason']));
        }
        $io->table(['result', 'count'], array_map(
            static fn (string $key): array => [$key, $report[$key]],
            ['created', 'replaced', 'skipped', 'failed', 'deleted'],
        ));
        $io->writeln(\sprintf('Removal of vanished pages: %s%s', $report['deletion'], isset($report['deletionSkippedReason']) ? ' ('.$report['deletionSkippedReason'].')' : ''));

        if ($report['failed'] === $report['pages']) {
            $io->error(\sprintf('Every page failed (%d); first failure: %s', $report['pages'], $report['failures'][0]['reason'] ?? 'unknown'));

            return Command::FAILURE;
        }
        $io->success(\sprintf('Imported into collection "%s".', $report['collection']));

        return Command::SUCCESS;
    }
}
