<?php

// file generated with AI assistance: Claude Code - 2026-11-06 14:00:00 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\Tests\Unit\Import;

use Dmstr\Firecrawl\Crawl\CrawlImporter;
use Dmstr\Firecrawl\Entity\CrawlProfile;
use Dmstr\Firecrawl\Import\PageOutcome;
use Dmstr\Firecrawl\Tests\Fixtures\InMemoryPageImporter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Kernel-free: the import of a profile's latest crawl output through the
 * page import port ({@see InMemoryPageImporter}), from a temporary project
 * directory.
 */
final class CrawlImporterTest extends TestCase
{
    private const array SIZES = ['chunkTargetSize' => 800, 'chunkOverlap' => 100, 'chunkMinSize' => 200];

    private string $projectDir;
    private InMemoryPageImporter $port;
    private CrawlImporter $importer;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/crawl-importer-'.bin2hex(random_bytes(4));
        $this->port = new InMemoryPageImporter();
        $this->importer = new CrawlImporter($this->port, $this->projectDir);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->projectDir);
    }

    public function testWithoutImportSectionNothingIsQueued(): void
    {
        $this->expectException(UnprocessableEntityHttpException::class);
        $this->importer->prepare($this->profile(null));
    }

    public function testThePagesGoInWithTheChunkSizesAndVanishedOnesAreRemoved(): void
    {
        $this->crawlOutput(['a.md' => ['https://Site.example/a#top', "# A\n\nText."], 'b.md' => ['https://site.example/b', 'B']]);

        $report = $this->importer->import($this->profile(['collection' => '/api/admin/collections/1'] + self::SIZES));

        self::assertSame(['https://site.example/a', 'https://site.example/b'], $this->port->importedUrls());
        self::assertSame([['/api/admin/collections/1', 'site.example']], $this->port->prepared);
        self::assertSame(self::SIZES, $this->port->targets[0]->chunkOptions);
        self::assertSame([['https://site.example/a', 'https://site.example/b']], $this->port->deleteCalls);
        self::assertSame([2, 0, CrawlImporter::DELETION_DONE], [$report['created'], $report['failed'], $report['deletion']]);
    }

    public function testAFailedPageKeepsEveryDocument(): void
    {
        $this->crawlOutput(['a.md' => ['https://site.example/a', 'A'], 'b.md' => ['https://site.example/b', 'B']]);
        $this->port->outcomes['https://site.example/b'] = PageOutcome::failed('content already stored');

        $report = $this->importer->import($this->profile(['collection' => 'docs'] + self::SIZES));

        self::assertSame([], $this->port->deleteCalls);
        self::assertSame(CrawlImporter::DELETION_SKIPPED, $report['deletion']);
        self::assertSame([['url' => 'https://site.example/b', 'reason' => 'content already stored']], $report['failures']);
    }

    /**
     * @param array<string, mixed>|null $import
     */
    private function profile(?array $import): CrawlProfile
    {
        return (new CrawlProfile())->setName('site')->setDiscover(['seeds' => ['https://site.example/']])->setImport($import);
    }

    /**
     * @param array<string, array{string, string}> $files file name => [URL, Markdown]
     */
    private function crawlOutput(array $files): void
    {
        $dir = $this->projectDir.'/var/crawl/site/documents';
        $index = [];
        foreach ($files as $file => [$url, $markdown]) {
            (new Filesystem())->dumpFile($dir.'/'.$file, $markdown."\n");
            $index[] = ['file' => $file, 'url' => $url, 'title' => strtoupper($file)];
        }
        (new Filesystem())->dumpFile($dir.'/documents.json', json_encode($index, \JSON_THROW_ON_ERROR));
    }
}
