<?php

// file generated with AI assistance: Claude Code - 2026-10-03 00:10:23 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\Command;

use Dmstr\Firecrawl\Connection\FirecrawlConnectionResolver;
use Dmstr\Firecrawl\Entity\Refs\FirecrawlJob;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Console twin of `GET /api/refs/firecrawl_jobs`: lists job reference rows,
 * newest first, optionally filtered by status, type and connection (UUID,
 * partial UUID, IRI or name of an active `firecrawl` connection).
 */
#[AsCommand(name: 'firecrawl:jobs', description: 'List Firecrawl job references')]
final class JobsCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly FirecrawlConnectionResolver $connectionResolver,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('status', null, InputOption::VALUE_REQUIRED, 'Only rows with this status (pending, scraping, completed, failed, ...)')
            ->addOption('type', null, InputOption::VALUE_REQUIRED, 'Only rows of this type (scrape, crawl or batch_scrape)')
            ->addOption('connection', 'c', InputOption::VALUE_REQUIRED, 'Only rows of this active firecrawl connection (UUID, partial UUID, IRI or name)')
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Maximum number of rows', '50');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $limit = filter_var($input->getOption('limit'), \FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (false === $limit) {
            $io->error('--limit must be a positive integer.');

            return Command::INVALID;
        }

        $criteria = [];
        foreach (['status', 'type'] as $field) {
            $value = $input->getOption($field);
            if (\is_string($value) && '' !== $value) {
                $criteria[$field] = $value;
            }
        }

        $reference = $input->getOption('connection');
        if (\is_string($reference) && '' !== $reference) {
            try {
                $criteria['apiConfiguration'] = $this->connectionResolver->resolve($reference);
            } catch (UnprocessableEntityHttpException $e) {
                $io->error($e->getMessage());

                return Command::FAILURE;
            }
        }

        /** @var list<FirecrawlJob> $jobs */
        $jobs = $this->entityManager->getRepository(FirecrawlJob::class)
            ->findBy($criteria, ['createdAt' => 'DESC'], $limit);

        if ([] === $jobs) {
            $io->note('No Firecrawl job references found.');

            return Command::SUCCESS;
        }

        $rows = [];
        foreach ($jobs as $job) {
            $rows[] = [
                (string) $job->getId(),
                $job->getApiConfiguration()->getName(),
                $job->getType(),
                $job->getStatus(),
                $job->getUrl(),
                $job->getFirecrawlId() ?? '-',
                $job->getCreatedAt()->format('Y-m-d H:i:s'),
                $job->getErrorMessage() ?? '',
            ];
        }
        $io->table(['ID', 'Connection', 'Type', 'Status', 'URL', 'Firecrawl ID', 'Created', 'Error'], $rows);
        $io->writeln(\sprintf('%d row(s).', \count($rows)));

        return Command::SUCCESS;
    }
}
