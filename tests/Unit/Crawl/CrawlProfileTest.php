<?php

// file generated with AI assistance: Claude Code - 2026-10-20 15:33:11 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\Tests\Unit\Crawl;

use Dmstr\Firecrawl\Crawl\CrawlProfile;
use PHPUnit\Framework\TestCase;

/**
 * Loading a crawl profile from YAML: defaults, the merged exclude list, the
 * name check and the rule that a profile with `auth` names its domains.
 */
final class CrawlProfileTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
    }

    public function testDefaultsAndExcludeTags(): void
    {
        $profile = CrawlProfile::fromFile('site', $this->yaml(<<<'YAML'
            discover:
              seeds: ["https://example.org/", ""]
              allowed_domains: [example.org]
            crawl:
              web:
                excluded_tags: [nav, footer]
                excluded_selector: ".cookie-banner"
            YAML));

        self::assertSame(['https://example.org/'], $profile->seeds);
        self::assertSame(3, $profile->maxDepth);
        self::assertSame(100, $profile->limit);
        self::assertNull($profile->auth);
        self::assertSame(['nav', 'footer', '.cookie-banner'], $profile->excludeTags());
        self::assertSame(5, $profile->withLimit(5)->limit);
        self::assertSame($profile->seeds, $profile->withLimit(5)->seeds);
    }

    public function testWithoutSelectorTheTagsAreTheExcludeList(): void
    {
        $profile = CrawlProfile::fromFile('site', $this->yaml("discover:\n  seeds: [\"https://example.org/\"]\ncrawl:\n  web:\n    excluded_tags: [nav]\n"));

        self::assertSame(['nav'], $profile->excludeTags());
    }

    public function testWaitFor(): void
    {
        $none = CrawlProfile::fromFile('site', $this->yaml("discover:\n  seeds: [\"https://example.org/\"]\n"));
        self::assertSame(0, $none->waitFor);

        $profile = CrawlProfile::fromFile('site', $this->yaml("discover:\n  seeds: [\"https://example.org/\"]\ncrawl:\n  web:\n    wait_for: 8000\n"));
        self::assertSame(8000, $profile->waitFor);
        self::assertSame(8000, $profile->withLimit(5)->waitFor);
    }

    public function testFilesBlock(): void
    {
        $off = CrawlProfile::fromFile('site', $this->yaml("discover:\n  seeds: [\"https://example.org/\"]\n"));
        self::assertFalse($off->convertsFiles());
        self::assertNull($off->converterFor('pdf'));
        self::assertTrue($off->pdfPageMarkers());
        self::assertFalse($off->ocr());
        self::assertSame(20 * 1024 * 1024, $off->maxFileBytes());

        $on = CrawlProfile::fromFile('site', $this->yaml(<<<'YAML'
            discover:
              seeds: ["https://example.org/"]
            crawl:
              files:
                enabled: true
                pdf_page_markers: false
                ocr: true
                max_bytes: 1000
                converters: { XLSX: Xlsx-Formulas }
            YAML));
        self::assertTrue($on->convertsFiles());
        self::assertFalse($on->pdfPageMarkers());
        self::assertTrue($on->ocr());
        self::assertSame(1000, $on->maxFileBytes());
        self::assertSame('xlsx-formulas', $on->converterFor('xlsx'));
        self::assertNull($on->converterFor('pdf'));
        self::assertTrue($on->withLimit(5)->convertsFiles());
    }

    public function testAuthNeedsAllowedDomains(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('needs discover.allowed_domains');

        CrawlProfile::fromFile('site', $this->yaml("discover:\n  seeds: [\"https://example.org/\"]\nauth:\n  type: yii2-form\n"));
    }

    public function testAuthWithAllowedDomainsIsKept(): void
    {
        $profile = CrawlProfile::fromFile('site', $this->yaml("discover:\n  seeds: [\"https://example.org/\"]\n  allowed_domains: [example.org]\nauth:\n  type: yii2-form\n"));

        self::assertSame(['type' => 'yii2-form'], $profile->auth);
    }

    public function testSeedsAreRequired(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('needs discover.seeds');

        CrawlProfile::fromFile('site', $this->yaml("discover:\n  seeds: []\n"));
    }

    public function testANameOutsideThePatternIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not found');

        CrawlProfile::fromFile('../site', $this->yaml("discover:\n  seeds: [\"https://example.org/\"]\n"));
    }

    private function yaml(string $content): string
    {
        $file = tempnam(sys_get_temp_dir(), 'crawl-profile-');
        file_put_contents($file, $content);
        $this->files[] = $file;

        return $file;
    }
}
