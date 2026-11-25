<?php

// file generated with AI assistance: Claude Code - 2026-10-03 07:29:38 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\Import;

/**
 * Port of the Firecrawl import into the application's document store. The bundle reads, paginates and
 * normalises crawl results; the application implements this interface and turns one page into one document.
 *
 * The bundle never instantiates an implementation: the application aliases this interface to its service.
 */
interface PageImporterInterface
{
    /**
     * Resolves the collection (UUID, IRI or name) and applies the one-source rule. Called BEFORE a job exists
     * (API processor, CLI) and again at job start.
     *
     * @throws \Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException unknown collection, or a
     *                                                                                   collection holding crawler-ingested chunks
     */
    public function prepare(string $collectionReference, ?string $site): ImportTarget;

    /**
     * Imports one page to completion: create or replace the document, store the file, extract, chunk and embed.
     * `$progress` is `callable(int $done, int $total): void` and reports the embedding progress within the page.
     * Returns a FAILED outcome for page-level problems (oversized page, URL too long, content hash held by another
     * document of the collection, extraction or embedding failure) and never throws for them; throws only when
     * the job cannot continue (entity manager closed, storage unavailable).
     */
    public function import(ImportTarget $target, CrawledPage $page, callable $progress): PageOutcome;

    /**
     * Removes the documents of origin `firecrawl` and `origin_site = $target->site` whose source URL is not in
     * `$seenUrls` (list of normalised URLs); documents without origin (uploads) and of other sites stay.
     * `$target->site` is never null here (the caller guarantees it). Returns the number of documents removed.
     *
     * @param list<string> $seenUrls
     */
    public function deleteVanished(ImportTarget $target, array $seenUrls): int;
}
