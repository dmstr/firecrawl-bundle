<?php

// file generated with AI assistance: Claude Code - 2026-10-03 00:10:23 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\Command;

use Dmstr\Firecrawl\Connection\FirecrawlConnectionResolver;
use Dmstr\Firecrawl\Entity\Refs\FirecrawlJob;
use Dmstr\Firecrawl\Jobs\JobFormatter;
use Dmstr\Firecrawl\Jobs\JobStarter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Console twin of `POST /api/refs/firecrawl_jobs`: starts a scrape, crawl or
 * batch scrape through {@see JobStarter} and prints the stored row as JSON.
 * The `url` argument takes one URL for scrape and crawl and one or more URLs
 * for a batch scrape (`firecrawl:jobs:start batch_scrape <url> <url>...`,
 * the same convention as `firecrawl:batch-scrape`). Exits
 * non-zero when the row ended `failed`, the connection cannot be resolved
 * or the arguments are invalid.
 */
#[AsCommand(name: 'firecrawl:jobs:start', description: 'Start a Firecrawl scrape, crawl or batch scrape and store its job reference')]
final class JobsStartCommand extends Command
{
    public function __construct(
        private readonly JobStarter $jobStarter,
        private readonly FirecrawlConnectionResolver $connectionResolver,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('type', InputArgument::REQUIRED, 'Job type: scrape (synchronous), crawl or batch_scrape')
            ->addArgument('url', InputArgument::REQUIRED | InputArgument::IS_ARRAY, 'Absolute http(s) URL; one or more for batch_scrape')
            ->addOption('connection', 'c', InputOption::VALUE_REQUIRED, 'Active firecrawl connection (UUID, partial UUID, IRI or name); default: the single active one')
            ->addOption('options', 'o', InputOption::VALUE_REQUIRED, 'Firecrawl request options as a JSON object, e.g. {"limit": 10}');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $type = (string) $input->getArgument('type');
        $urls = array_values(array_map('strval', (array) $input->getArgument('url')));

        if (!\in_array($type, FirecrawlJob::TYPES, true)) {
            $io->error(\sprintf('Unknown type "%s"; expected one of: %s.', $type, implode(', ', FirecrawlJob::TYPES)));

            return Command::INVALID;
        }
        $batch = FirecrawlJob::TYPE_BATCH_SCRAPE === $type;
        if (!$batch && 1 !== \count($urls)) {
            $io->error(\sprintf('A %s takes exactly one URL; use batch_scrape for several.', $type));

            return Command::INVALID;
        }
        foreach ($urls as $url) {
            if (1 !== preg_match('#^https?://\S+$#i', $url)) {
                $io->error(\sprintf('"%s" is not an absolute http(s) URL.', $url));

                return Command::INVALID;
            }
        }

        $options = null;
        $rawOptions = $input->getOption('options');
        if (\is_string($rawOptions) && '' !== trim($rawOptions)) {
            try {
                $options = json_decode($rawOptions, true, 512, \JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                $io->error('--options is not valid JSON: '.$e->getMessage());

                return Command::INVALID;
            }
            if (!\is_array($options) || (array_is_list($options) && [] !== $options)) {
                $io->error('--options must be a JSON object.');

                return Command::INVALID;
            }
        }

        try {
            $connection = $this->connectionResolver->resolve($input->getOption('connection'));
        } catch (UnprocessableEntityHttpException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $job = $batch
            ? $this->jobStarter->start($type, '', $options, $connection, $urls)
            : $this->jobStarter->start($type, $urls[0], $options, $connection);

        $output->writeln(JobFormatter::toJson($job));

        if (FirecrawlJob::STATUS_FAILED === $job->getStatus()) {
            $io->error((string) $job->getErrorMessage());

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
