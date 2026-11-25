<?php

// file generated with AI assistance: Claude Code - 2026-10-19 13:21:25 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\Command;

use Dmstr\Firecrawl\Crawl\CrawlProfile;
use Dmstr\Firecrawl\Crawl\CrawlProfileLoader;
use Dmstr\Firecrawl\Crawl\CrawlSessionOpener;
use Dmstr\Firecrawl\Crawl\FormLogin;
use Dmstr\Firecrawl\Crawl\RedirectResolver;
use Dmstr\Firecrawl\Crawl\UrlClassifier;
use Dmstr\Firecrawl\Client\FirecrawlApiException;
use Dmstr\Firecrawl\Client\FirecrawlClientInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Phase 1 of a crawl profile: finds the pages and document files of a site
 * without assuming a sitemap, and saves them to
 * `var/crawl/<profile>/urls.json` for review (`seeds`, `pages`, `files`,
 * `broken`). The command walks the links itself, breadth first from each
 * seed, with one Firecrawl scrape per page (format `links`); the result is
 * sorted by {@see UrlClassifier}. It does not use Firecrawl's crawl endpoint
 * because that obeys robots.txt (a site that disallows crawlers yields no
 * pages) and cannot be told otherwise on this instance, while a single
 * scrape does not check it. Redirects are resolved by {@see RedirectResolver}
 * first, because Firecrawl drops the cookie when it follows one. With an `auth` block the command logs in first
 * ({@see FormLogin}, through {@see CrawlSessionOpener}) and sends only the session cookie to Firecrawl, in the
 * scrape's `headers`; it is not stored by this command.
 */
#[AsCommand(name: 'app:crawl:discover', description: 'Find the pages and files of a site by following links with Firecrawl scrapes and save them to urls.json')]
final class CrawlDiscoverCommand extends Command
{
    public function __construct(
        private readonly CrawlSessionOpener $session,
        private readonly CrawlProfileLoader $profiles,
        private readonly RedirectResolver $redirects,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('profile', InputArgument::REQUIRED, 'Name of a crawl profile (/api/admin/crawl_profiles)')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Overrides discover.limit (pages per seed)')
            ->addOption('no-login', null, InputOption::VALUE_NONE, 'Skip the login even if the profile has an auth block');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $name = (string) $input->getArgument('profile');
            [$profile, $stored] = $this->profiles->load($name);
            if (null !== $input->getOption('limit')) {
                $profile = $profile->withLimit((int) $input->getOption('limit'));
            }

            [$client, $cookie] = $this->session->open($profile, (string) $stored->getFirecrawlConnection()?->getId(), !$input->getOption('no-login'));
            if (null !== $cookie) {
                $io->writeln('Logged in (session cookie obtained).');
            }
            $classifier = new UrlClassifier($profile);

            $results = [];
            $seen = [];
            foreach ($profile->seeds as $seed) {
                $io->writeln(\sprintf('Walking %s (limit %d, depth %d) ...', $seed, $profile->limit, $profile->maxDepth));
                $seedResults = $this->walk($client, $classifier, $profile, $seed, $cookie, $seen, $io);
                $io->writeln(\sprintf('  %d result(s)', \count($seedResults)));
                array_push($results, ...$seedResults);
            }
        } catch (FirecrawlApiException $e) {
            $io->error('Firecrawl failed: '.$e->getMessage().(null !== $e->getStatusCode() ? ' (HTTP '.$e->getStatusCode().') '.$e->getResponseBody() : ''));

            return Command::FAILURE;
        } catch (\RuntimeException|\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $sorted = $classifier->classify($results);
        $manifest = ['seeds' => $profile->seeds] + $sorted;

        $dir = $this->projectDir.'/var/crawl/'.$profile->name;
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            $io->error('Cannot create '.$dir);

            return Command::FAILURE;
        }
        $path = $dir.'/urls.json';
        file_put_contents($path, json_encode($manifest, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE)."\n");

        $io->table(['group', 'count'], [
            ['pages', \count($sorted['pages'])],
            ['files', \count($sorted['files'])],
            ['broken links', \count($sorted['broken'])],
        ]);
        $io->success('Saved '.$path);

        return Command::SUCCESS;
    }

    /**
     * Breadth-first walk from one seed: scrapes a page, queues its in-scope
     * page links one level deeper, stops at `limit` pages or `maxDepth`.
     *
     * @param array<string, true> $seen URLs already queued by earlier seeds, updated here
     *
     * @return list<array<string, mixed>> per page `metadata` and `links`
     */
    private function walk(FirecrawlClientInterface $client, UrlClassifier $classifier, CrawlProfile $profile, string $seed, ?string $cookie, array &$seen, SymfonyStyle $io): array
    {
        $options = ['formats' => ['links'], 'onlyMainContent' => false];
        if (null !== $cookie) {
            $options['headers'] = ['Cookie' => $cookie];
        }

        $queue = [[UrlClassifier::withoutFragment($seed), 0]];
        $seen[$queue[0][0]] = true;
        $results = [];

        while ([] !== $queue && \count($results) < $profile->limit) {
            [$url, $depth] = array_shift($queue);

            // Firecrawl would lose the cookie on a redirect, so scrape the final URL; one that is queued already is a duplicate.
            $final = $this->redirects->resolve($url, $cookie);
            if ($final !== $url) {
                if (isset($seen[$final]) || !$classifier->inScope($final) || $classifier->isFile($final)) {
                    $io->writeln(\sprintf('  skipped %s (redirects to %s)', $url, $final), OutputInterface::VERBOSITY_VERBOSE);
                    continue;
                }
                $seen[$final] = true;
                $url = $final;
            }

            try {
                $answer = $client->scrape(['url' => $url] + $options);
            } catch (FirecrawlApiException $e) {
                $io->warning(\sprintf('Skipped %s: %s', $url, $e->getMessage()));
                continue;
            }

            $data = \is_array($answer['data'] ?? null) ? $answer['data'] : $answer;
            $metadata = \is_array($data['metadata'] ?? null) ? $data['metadata'] : [];
            $links = array_values(array_filter((array) ($data['links'] ?? []), 'is_string'));
            $metadata['url'] = $url;
            $results[] = ['metadata' => $metadata, 'links' => $links];
            $io->writeln(\sprintf('  [%d] %s (HTTP %s, %d links)', $depth, $url, $metadata['statusCode'] ?? '?', \count($links)), OutputInterface::VERBOSITY_VERBOSE);

            if ($depth >= $profile->maxDepth || (int) ($metadata['statusCode'] ?? 200) >= 400) {
                continue;
            }
            foreach ($links as $link) {
                $link = UrlClassifier::withoutFragment($link);
                if (!isset($seen[$link]) && $classifier->inScope($link) && !$classifier->isFile($link)) {
                    $seen[$link] = true;
                    $queue[] = [$link, $depth + 1];
                }
            }
        }

        return $results;
    }
}
