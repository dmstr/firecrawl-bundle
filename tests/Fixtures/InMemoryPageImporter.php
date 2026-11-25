<?php

// file generated with AI assistance: Claude Code - 2026-10-03 07:43:59 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\Tests\Fixtures;

use Dmstr\Firecrawl\Import\CrawledPage;
use Dmstr\Firecrawl\Import\ImportTarget;
use Dmstr\Firecrawl\Import\PageImporterInterface;
use Dmstr\Firecrawl\Import\PageOutcome;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Kernel-free stand-in for the application's page importer: records every
 * call and answers with scripted outcomes.
 *
 *  - prepare(): returns a target for any reference except the ones in
 *    `$unknownCollections` (422 like the application);
 *  - import(): answers `$outcomes[$url]` (a PageOutcome, or a Throwable that
 *    is thrown), `created` with a derived id otherwise; reports
 *    `$progressSteps` embedding steps through the progress callable first;
 *  - deleteVanished(): records the seen URLs and returns `$deleteResult`.
 */
final class InMemoryPageImporter implements PageImporterInterface
{
    public const string COLLECTION_ID = '0199a5b2-0000-7000-8000-000000000001';

    /** @var array<string, PageOutcome|\Throwable> keyed by normalised page URL */
    public array $outcomes = [];

    /** @var list<string> collection references that prepare() rejects */
    public array $unknownCollections = [];

    public int $progressSteps = 0;

    public int $deleteResult = 0;

    /** @var list<array{string, string|null}> collection reference and site per prepare() call */
    public array $prepared = [];

    /** @var list<CrawledPage> */
    public array $imported = [];

    /** @var list<ImportTarget> */
    public array $targets = [];

    /** @var list<list<string>> seen URLs per deleteVanished() call */
    public array $deleteCalls = [];

    public function prepare(string $collectionReference, ?string $site): ImportTarget
    {
        $this->prepared[] = [$collectionReference, $site];
        if (\in_array($collectionReference, $this->unknownCollections, true)) {
            throw new UnprocessableEntityHttpException(\sprintf('Unknown collection "%s".', $collectionReference));
        }

        return new ImportTarget(self::COLLECTION_ID, $collectionReference, $site);
    }

    public function import(ImportTarget $target, CrawledPage $page, callable $progress): PageOutcome
    {
        $this->imported[] = $page;
        $this->targets[] = $target;

        for ($done = 1; $done <= $this->progressSteps; ++$done) {
            $progress($done, $this->progressSteps);
        }

        $outcome = $this->outcomes[$page->url] ?? PageOutcome::created(self::documentId($page->url));
        if ($outcome instanceof \Throwable) {
            throw $outcome;
        }

        return $outcome;
    }

    public function deleteVanished(ImportTarget $target, array $seenUrls): int
    {
        $this->targets[] = $target;
        $this->deleteCalls[] = $seenUrls;

        return $this->deleteResult;
    }

    /** Deterministic document id of a page URL. */
    public static function documentId(string $url): string
    {
        $hash = md5($url);

        return \sprintf('%s-%s-4%s-8%s-%s', substr($hash, 0, 8), substr($hash, 8, 4), substr($hash, 13, 3), substr($hash, 17, 3), substr($hash, 20, 12));
    }

    /** @return list<string> URLs of the pages handed to import(), in order */
    public function importedUrls(): array
    {
        return array_map(static fn (CrawledPage $page): string => $page->url, $this->imported);
    }
}
