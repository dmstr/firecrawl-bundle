<?php

// file generated with AI assistance: Claude Code - 2026-11-06 14:00:00 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\Crawl;

use Dmstr\Firecrawl\Entity\CrawlProfile;
use Dmstr\Firecrawl\Import\CrawledPage;
use Dmstr\Firecrawl\Import\ImportTarget;
use Dmstr\Firecrawl\Import\PageImporterInterface;
use Dmstr\Firecrawl\Import\SetextHeadingNormalizer;
use Dmstr\Firecrawl\Import\UrlNormalizer;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Puts the Markdown files of a profile's latest crawl
 * (`var/crawl/<profile>/documents/`, listed in `documents.json`) into the
 * collection of its `import` section, through the application's
 * {@see PageImporterInterface}, the port the Firecrawl job import uses: a
 * page is created, replaced (new text or new chunk sizes) or skipped
 * (unchanged), keyed by its normalised URL.
 *
 * The documents are attributed to the host of the profile's first seed
 * (`origin_site`). With `deleteVanished` (default) the documents of that
 * site whose URL the crawl no longer has are removed afterwards, but only
 * when every page was imported: an incomplete run removes nothing.
 *
 * Report: {created, replaced, skipped, failed, deleted, pages, site,
 * collection, failures: [{url, reason}], deletion: 'off'|'done'|'skipped'}.
 */
final class CrawlImporter
{
    public const string DELETION_OFF = 'off';
    public const string DELETION_DONE = 'done';
    public const string DELETION_SKIPPED = 'skipped';

    public function __construct(
        private readonly PageImporterInterface $pageImporter,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    /**
     * Checks the profile's import section and resolves its collection;
     * called before a job exists and again when it runs.
     *
     * @throws UnprocessableEntityHttpException no import section, or an unknown collection
     */
    public function prepare(CrawlProfile $profile): ImportTarget
    {
        $import = $profile->getImport();
        if (null === $import || '' === (string) ($import['collection'] ?? '')) {
            throw new UnprocessableEntityHttpException(\sprintf('Crawl profile "%s" has no import section; set its collection and chunk sizes first.', $profile->getName()));
        }

        $seed = (string) ($profile->getDiscover()['seeds'][0] ?? '');
        $chunkOptions = array_intersect_key($import, array_flip(['chunkTargetSize', 'chunkOverlap', 'chunkMinSize']));

        return $this->pageImporter->prepare((string) $import['collection'], UrlNormalizer::host($seed))->withChunkOptions($chunkOptions);
    }

    /**
     * @return array<string, mixed> the report (see the class docblock)
     *
     * @throws \RuntimeException when the crawl output is missing or holds no pages
     */
    public function import(CrawlProfile $profile, ?callable $log = null): array
    {
        $target = $this->prepare($profile);
        $dir = $this->projectDir.'/var/crawl/'.$profile->getName().'/documents';
        $index = is_file($dir.'/documents.json') ? json_decode((string) file_get_contents($dir.'/documents.json'), true) : null;
        if (!\is_array($index) || [] === $index) {
            throw new \RuntimeException(\sprintf('No documents in %s/documents.json; run app:crawl:run %s first.', $dir, $profile->getName()));
        }

        $requested = (bool) ($profile->getImport()['deleteVanished'] ?? true);
        $report = ['created' => 0, 'replaced' => 0, 'skipped' => 0, 'failed' => 0, 'deleted' => 0, 'pages' => 0, 'site' => $target->site, 'collection' => $target->collectionName, 'failures' => [], 'deletion' => $requested ? self::DELETION_SKIPPED : self::DELETION_OFF];
        $seen = [];

        foreach ($index as $entry) {
            ++$report['pages'];
            $rawUrl = \is_array($entry) ? (string) ($entry['url'] ?? '') : '';
            $url = UrlNormalizer::normalize($rawUrl);
            $file = \is_array($entry) ? basename((string) ($entry['file'] ?? '')) : '';
            $markdown = '' !== $file && is_file($dir.'/'.$file) ? (string) file_get_contents($dir.'/'.$file) : '';

            if (null === $url || '' === trim($markdown)) {
                $report['failures'][] = ['url' => $rawUrl, 'reason' => null === $url ? 'The URL is not an absolute http(s) URL.' : 'The Markdown file is missing or empty.'];
                ++$report['failed'];
                continue;
            }

            $seen[$url] = true;
            $title = trim((string) ($entry['title'] ?? ''));
            $outcome = $this->pageImporter->import($target, new CrawledPage($url, SetextHeadingNormalizer::normalize($markdown), '' !== $title ? $title : null), static function (): void {
            });
            if ($outcome->isFailed()) {
                $report['failures'][] = ['url' => $url, 'reason' => (string) $outcome->reason];
                ++$report['failed'];
            } else {
                ++$report[$outcome->status];
            }
            if (null !== $log) {
                $log(\sprintf('%s %s', $outcome->status, $url));
            }
        }

        if ($requested) {
            if ($report['failed'] > 0 || null === $target->site) {
                $report['deletionSkippedReason'] = $report['failed'] > 0
                    ? \sprintf('%d page(s) failed; nothing is removed after an incomplete import.', $report['failed'])
                    : 'The profile has no seed, so its documents have no site.';
            } else {
                $report['deleted'] = $this->pageImporter->deleteVanished($target, array_keys($seen));
                $report['deletion'] = self::DELETION_DONE;
            }
        }

        return $report;
    }
}
