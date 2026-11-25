<?php

// file generated with AI assistance: Claude Code - 2026-10-19 13:49:00 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\Command;

use Dmstr\Firecrawl\Crawl\CrawlProfile;
use Dmstr\Firecrawl\Crawl\CrawlProfileLoader;
use Dmstr\Firecrawl\Crawl\CrawlSessionOpener;
use Dmstr\Firecrawl\Crawl\FileConverter;
use Dmstr\Firecrawl\Crawl\FileDownloader;
use Dmstr\Firecrawl\Crawl\PageFilename;
use Dmstr\Firecrawl\Crawl\UrlClassifier;
use Dmstr\Firecrawl\Client\FirecrawlApiException;
use Dmstr\Firecrawl\Connection\FileConverterConnectionResolver;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Phase 2 of a crawl profile: scrapes the pages listed in
 * `var/crawl/<profile>/urls.json` (written by `app:crawl:discover`) to
 * Markdown and saves one file per page to `var/crawl/<profile>/documents/`,
 * with an index `documents.json` (file, URL, title, status). The profile's
 * `crawl.web.excluded_tags` and `excluded_selector` become Firecrawl's
 * `scrapeOptions.excludeTags`. With `--raw` nothing is excluded and the files
 * go to `documents-raw/`, to compare before and after.
 *
 * With `crawl.convertFiles` in the profile the document files of urls.json are
 * downloaded and converted to Markdown by the active file converter
 * connection as well.
 *
 * Pages are scraped one by one, so the session cookie goes into synchronous
 * requests only and no job row (which would store the request options) is
 * created. Importing the files into a collection is a separate step, after the
 * Markdown has been reviewed.
 *
 * A page or file whose Markdown is byte for byte the one of an earlier URL
 * (a site that serves a page under several paths) is not written again but
 * counted as a duplicate: the upload would refuse it anyway (same sha256 in
 * the collection), and a review should not see it twice.
 */
#[AsCommand(name: 'app:crawl:run', description: 'Scrape the pages of a discovered urls.json to Markdown files, with the profile\'s excluded tags')]
final class CrawlRunCommand extends Command
{
    public function __construct(
        private readonly CrawlSessionOpener $session,
        private readonly CrawlProfileLoader $profiles,
        private readonly FileDownloader $downloader,
        private readonly FileConverter $converter,
        private readonly FileConverterConnectionResolver $fileConverters,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('profile', InputArgument::REQUIRED, 'Name of a crawl profile (/api/admin/crawl_profiles)')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Scrape only the first N pages of the list')
            ->addOption('only', null, InputOption::VALUE_REQUIRED, 'Scrape only the pages whose URL contains this text')
            ->addOption('raw', null, InputOption::VALUE_NONE, 'Exclude nothing and write to documents-raw/ (to compare)')
            ->addOption('no-files', null, InputOption::VALUE_NONE, 'Do not download and convert the document files, even if the profile asks for it')
            ->addOption('no-login', null, InputOption::VALUE_NONE, 'Skip the login even if the profile has an auth block');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $name = (string) $input->getArgument('profile');
            [$profile, $stored] = $this->profiles->load($name);
            $base = $this->projectDir.'/var/crawl/'.$name;

            $manifest = is_file($base.'/urls.json') ? json_decode((string) file_get_contents($base.'/urls.json'), true) : null;
            if (!\is_array($manifest) || [] === ($manifest['pages'] ?? [])) {
                throw new \RuntimeException(\sprintf('No pages in %s/urls.json; run app:crawl:discover %s first.', $base, $name));
            }

            $pages = array_values(array_filter((array) $manifest['pages'], static fn (mixed $u): bool => \is_string($u)));
            if (null !== $input->getOption('only')) {
                $pages = array_values(array_filter($pages, static fn (string $u): bool => str_contains($u, (string) $input->getOption('only'))));
            }
            if (null !== $input->getOption('limit')) {
                $pages = \array_slice($pages, 0, (int) $input->getOption('limit'));
            }

            $files = [];
            if ($profile->convertsFiles() && !$input->getOption('no-files')) {
                // urls.json lists a file as {url, text}; a plain URL string is accepted too.
                $files = array_values(array_filter(array_map(
                    static fn (mixed $f): ?string => \is_array($f) ? (\is_string($f['url'] ?? null) ? $f['url'] : null) : (\is_string($f) ? $f : null),
                    (array) ($manifest['files'] ?? []),
                )));
                if (null !== $input->getOption('only')) {
                    $files = array_values(array_filter($files, static fn (string $u): bool => str_contains($u, (string) $input->getOption('only'))));
                }
                if (null !== $input->getOption('limit')) {
                    $files = \array_slice($files, 0, (int) $input->getOption('limit'));
                }
            }

            $raw = (bool) $input->getOption('raw');
            $dir = $base.'/'.($raw ? 'documents-raw' : 'documents');
            if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new \RuntimeException('Cannot create '.$dir);
            }

            // Before the login and the clean-up, so a missing converter keeps the previous result.
            $fileConverterConnection = [] !== $files ? $this->fileConverters->resolve()->getConfigJson() : [];

            [$client, $cookie] = $this->session->open($profile, (string) $stored->getFirecrawlConnection()?->getId(), !$input->getOption('no-login'));
            if (null !== $cookie) {
                $io->writeln('Logged in (session cookie obtained).');
            }

            // The folder is this command's own output: start clean so a page that left the list leaves no stale file.
            // Only after the login and the connection succeeded, so a failed start keeps the previous result.
            foreach ([...(glob($dir.'/*.md') ?: []), $dir.'/documents.json'] as $stale) {
                if (is_file($stale)) {
                    unlink($stale);
                }
            }

            $options = ['formats' => ['markdown'], 'onlyMainContent' => false];
            if (!$raw && [] !== $profile->excludeTags()) {
                $options['excludeTags'] = $profile->excludeTags();
            }
            if ($profile->waitFor > 0) {
                $options['waitFor'] = $profile->waitFor;
            }
            if (null !== $cookie) {
                $options['headers'] = ['Cookie' => $cookie];
            }

            $io->writeln(\sprintf('Scraping %d page(s)%s ...', \count($pages), $raw ? ' without exclusions' : ' with excludeTags: '.implode(', ', $profile->excludeTags())));
            $index = [];
            $used = [];
            $skipped = 0;
            $hashes = [];
            $duplicates = 0;
            foreach ($pages as $url) {
                try {
                    $answer = $client->scrape(['url' => $url] + $options);
                } catch (FirecrawlApiException $e) {
                    $io->warning(\sprintf('Skipped %s: %s', $url, $e->getMessage()));
                    ++$skipped;
                    continue;
                }

                $data = \is_array($answer['data'] ?? null) ? $answer['data'] : $answer;
                $metadata = \is_array($data['metadata'] ?? null) ? $data['metadata'] : [];
                $markdown = trim((string) ($data['markdown'] ?? ''));
                $status = (int) ($metadata['statusCode'] ?? 200);
                if ('' === $markdown || $status >= 400) {
                    $io->warning(\sprintf('Skipped %s: HTTP %d, %d characters of Markdown.', $url, $status, \strlen($markdown)));
                    ++$skipped;
                    continue;
                }
                // A URL list from before the HTML check of discover may still list a download as a page.
                if (!UrlClassifier::isHtml($metadata)) {
                    $io->warning(\sprintf('Skipped %s: not a web page (%s).', $url, $metadata['contentType']));
                    ++$skipped;
                    continue;
                }

                if (self::isDuplicate($io, $url, $markdown, $hashes)) {
                    ++$duplicates;
                    continue;
                }

                $file = PageFilename::uniqueFromUrl($url, $used);
                file_put_contents($dir.'/'.$file, $markdown."\n");
                $index[] = ['file' => $file, 'url' => $url, 'title' => (string) ($metadata['title'] ?? ''), 'status' => $status, 'characters' => \strlen($markdown)];
                $io->writeln(\sprintf('  %s (%d characters)', $file, \strlen($markdown)), OutputInterface::VERBOSITY_VERBOSE);
            }

            if ([] !== $files) {
                $io->writeln(\sprintf('Converting %d file(s) ...', \count($files)));
                [$skippedFiles, $duplicateFiles] = $this->convertFiles($io, $profile, $fileConverterConnection, $files, $cookie, $dir, $used, $index, $hashes);
                $skipped += $skippedFiles;
                $duplicates += $duplicateFiles;
            }
        } catch (FirecrawlApiException $e) {
            $io->error('Firecrawl failed: '.$e->getMessage());

            return Command::FAILURE;
        } catch (\RuntimeException|\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        file_put_contents($dir.'/documents.json', json_encode($index, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE)."\n");

        $io->table(['result', 'count'], [['written', \count($index)], ['duplicates', $duplicates], ['skipped', $skipped]]);
        $io->success('Saved to '.$dir);

        return Command::SUCCESS;
    }

    /**
     * Downloads each file with the session cookie, converts it in the converter container and writes the
     * Markdown next to the pages. A file that fails is skipped with a warning, like a page.
     *
     * @param array<string, mixed>               $fileConverterConnection configJson of the file converter connection
     * @param list<string>                       $files
     * @param array<string, true>                $used
     * @param list<array<string, string|int|null>> $index
     * @param array<string, string>              $hashes
     *
     * @return array{int, int} the number of skipped and of duplicate files
     */
    private function convertFiles(SymfonyStyle $io, CrawlProfile $profile, array $fileConverterConnection, array $files, ?string $cookie, string $dir, array &$used, array &$index, array &$hashes): array
    {
        $skipped = 0;
        $duplicates = 0;
        $classifier = new UrlClassifier($profile);
        foreach ($files as $url) {
            $ext = $classifier->fileExtension($url);
            $headers = [];
            try {
                // The cookie is for the site itself, not for a CDN or image proxy that hosts its files.
                $bytes = $this->downloader->download($url, $classifier->isSiteHost($url) ? $cookie : null, $profile->maxFileBytes(), $headers);
                // A download URL without an extension (`/download?id=…`) gets the type from the response.
                $ext ??= $classifier->fileExtensionFromHeaders($headers);
                if (null === $ext) {
                    throw new \RuntimeException(\sprintf('not a known document type (Content-Type %s)', $headers['content-type'][0] ?? 'none'));
                }
                $result = $this->converter->convert($fileConverterConnection, $bytes, $ext, $profile->converterFor($ext), $profile->pdfPageMarkers(), $profile->ocr());
            } catch (\RuntimeException $e) {
                $io->warning(\sprintf('Skipped %s: %s', $url, $e->getMessage()));
                ++$skipped;
                continue;
            }

            $markdown = trim($result['markdown']);
            // An image-only (scanned) PDF must not be written as an empty document.
            if (\strlen((string) preg_replace('/\s+|<!--page:\d+-->/', '', $markdown)) < 10) {
                $io->warning(\sprintf('Skipped %s: no text in the file (a scan?).', $url));
                ++$skipped;
                continue;
            }
            if (self::isDuplicate($io, $url, $markdown, $hashes)) {
                ++$duplicates;
                continue;
            }

            $file = PageFilename::uniqueFromUrl($url, $used);
            file_put_contents($dir.'/'.$file, $markdown."\n");
            $index[] = [
                'file' => $file,
                'url' => $url,
                'title' => $result['title'] ?? UrlClassifier::fileName($headers) ?? basename((string) parse_url($url, \PHP_URL_PATH)),
                'status' => 200,
                'characters' => \strlen($markdown),
                'converter' => $result['converter'],
                'pages' => $result['pages'],
                'route' => $result['route'],
            ];
            $converted = $result['converter'].(null !== $result['route'] ? ' → '.$result['route'] : '');
            // OCR is slow and can misread: show it without -v, so a crawl log tells which documents it touched.
            $io->writeln(\sprintf('  %s (%d characters, %s)', $file, \strlen($markdown), $converted), null !== $result['route'] ? OutputInterface::VERBOSITY_NORMAL : OutputInterface::VERBOSITY_VERBOSE);
        }

        return [$skipped, $duplicates];
    }

    /**
     * Whether an earlier URL produced the same Markdown; the hash is the
     * sha256 of the bytes written, the one the upload compares.
     *
     * @param array<string, string> $hashes URL by hash, updated here
     */
    private static function isDuplicate(SymfonyStyle $io, string $url, string $markdown, array &$hashes): bool
    {
        $hash = hash('sha256', $markdown."\n");
        if (isset($hashes[$hash])) {
            $io->writeln(\sprintf('  duplicate of %s: %s', $hashes[$hash], $url), OutputInterface::VERBOSITY_VERBOSE);

            return true;
        }
        $hashes[$hash] = $url;

        return false;
    }
}

// - revised 2026-10-20
// - revised 2026-10-22
// - revised 2026-11-06
// - revised 2026-11-17
// - revised 2026-11-18
