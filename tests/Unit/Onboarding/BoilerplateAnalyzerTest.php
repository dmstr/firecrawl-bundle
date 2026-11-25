<?php

// file generated with AI assistance: Claude Code - 2026-10-13 21:47:18 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\Tests\Unit\Onboarding;

use Dmstr\Firecrawl\Onboarding\BoilerplateAnalyzer;
use Dmstr\Firecrawl\Onboarding\BoilerplateForm;
use PHPUnit\Framework\TestCase;

final class BoilerplateAnalyzerTest extends TestCase
{
    private static function page(string $main, string $crumb = 'Home / Products'): string
    {
        return <<<HTML
            <!doctype html><html><head><title>t</title><script>var x = 1;</script></head><body>
            <div id="cookie-consent"><p>We use cookies to improve this site.</p><button>OK</button></div>
            <div class="page">
              <div class="top-bar"><div class="top-bar-inner">Hotline +49 711 123456 · Contact us today</div></div>
              <header class="site-head"><a href="/">Logo</a><nav><a href="/a">Products</a><a href="/b">Service</a></nav></header>
              <div class="breadcrumb">{$crumb}</div>
              <main class="content"><article><header><h1>{$main}</h1></header><p>{$main} body text that differs per page.</p></article></main>
              <footer><p>© 2026 Example GmbH · Imprint · Privacy</p></footer>
            </div>
            </body></html>
            HTML;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function bySelector(array $result): array
    {
        return array_column($result['candidates'], null, 'selector');
    }

    public function testCrossPageComparisonPreselectsRegionsOnEveryPage(): void
    {
        $result = new BoilerplateAnalyzer()->analyze([
            ['url' => 'https://example.org/a', 'html' => self::page('Alpha', 'Home / Alpha')],
            ['url' => 'https://example.org/b', 'html' => self::page('Beta', 'Home / Beta')],
            ['url' => 'https://example.org/c', 'html' => self::page('Gamma', 'Home / Gamma')],
        ]);
        $c = self::bySelector($result);

        self::assertSame(3, $result['pageCount']);
        self::assertSame(['script', 'style', 'noscript'], $result['alwaysExcluded']);

        // Category matches.
        self::assertSame('cookie/consent', $c['#cookie-consent']['category']);
        self::assertTrue($c['#cookie-consent']['preselected']);
        self::assertTrue($c['#cookie-consent']['identicalText']);
        self::assertSame('navigation', $c['.breadcrumb']['category']);
        self::assertFalse($c['.breadcrumb']['identicalText']);
        self::assertTrue($c['.breadcrumb']['preselected'], 'strong category, differs per page');

        // Semantic tags: the site header (with class, nav inside) is told
        // apart from the bare article header.
        self::assertTrue($c['footer']['preselected']);
        self::assertTrue($c['header.site-head']['preselected']);
        self::assertTrue($c['header.site-head']['identicalText']);
        self::assertFalse($c['header']['preselected'], 'article headers differ per page');
        self::assertFalse($c['header']['identicalText']);
        self::assertArrayNotHasKey('nav', $c, 'the nav lies inside the site header');

        // Repetition without a telling name: only the outermost block.
        self::assertSame('repeated', $c['.top-bar']['category']);
        self::assertTrue($c['.top-bar']['preselected']);
        self::assertArrayNotHasKey('.top-bar-inner', $c);

        // Main content and the page wrapper differ per page and are not offered.
        self::assertArrayNotHasKey('.content', $c);
        self::assertArrayNotHasKey('.page', $c);
    }

    public function testSinglePagePreselectsCategoriesButNotHeader(): void
    {
        $result = new BoilerplateAnalyzer()->analyze([['url' => 'https://example.org/', 'html' => self::page('Alpha')]]);
        $c = self::bySelector($result);

        self::assertTrue($c['#cookie-consent']['preselected']);
        self::assertTrue($c['footer']['preselected']);
        self::assertFalse($c['header']['preselected']);
        self::assertArrayNotHasKey('.top-bar', $c, 'no repetition with one page');
        self::assertFalse($c['footer']['identicalText']);
    }

    public function testFormIsACheckboxListWithThePreselection(): void
    {
        $result = new BoilerplateAnalyzer()->analyze([
            ['url' => 'https://example.org/a', 'html' => self::page('Alpha')],
            ['url' => 'https://example.org/b', 'html' => self::page('Beta')],
        ]);
        $form = BoilerplateForm::build($result['candidates'], $result['pageCount']);
        $exclude = $form['schema']['properties']['exclude'];

        self::assertSame('boilerplate', $form['id']);
        self::assertTrue($exclude['uniqueItems']);
        self::assertSame(array_column($result['candidates'], 'selector'), $exclude['items']['enum']);
        self::assertCount(\count($exclude['items']['enum']), $exclude['items']['x-enumTitles']);
        self::assertStringContainsString('2/2 pages, same text', $exclude['items']['x-enumTitles'][0]);
        self::assertContains('#cookie-consent', $form['value']['exclude']);
        self::assertNotContains('header', $form['value']['exclude']);
        self::assertTrue($form['value']['onlyMainContent']);
    }

    public function testFormWithoutCandidatesHasNoEmptyEnum(): void
    {
        $form = BoilerplateForm::build([], 1);

        self::assertArrayNotHasKey('enum', $form['schema']['properties']['exclude']['items']);
        self::assertSame(0, $form['schema']['properties']['exclude']['maxItems']);
        self::assertSame([], $form['value']['exclude']);
    }
}
