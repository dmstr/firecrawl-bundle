<?php

// file generated with AI assistance: Claude Code - 2026-10-03 07:43:59 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\Tests\Unit\Import;

use Dmstr\Firecrawl\Import\SetextHeadingNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Kernel-free checks of the setext-to-ATX rewrite applied to every crawled
 * page before it is stored: setext H1/H2 become ATX; thematic breaks, front
 * matter, fenced and indented code, tables, list items, blockquotes and
 * multi-line paragraphs stay as they are; the rewrite is idempotent.
 */
final class SetextHeadingNormalizerTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}> input, expected output
     */
    public static function fixtures(): iterable
    {
        yield 'h1 with =' => ["Title\n=====\n\nBody", "# Title\n\nBody"];
        yield 'h2 with -' => ["Sub\n---\nText", "## Sub\nText"];
        yield 'single underline character' => ["Title\n=\n\nSub\n-", "# Title\n\n## Sub"];
        yield 'underline with trailing whitespace' => ["Title\n===   \t\nBody", "# Title\nBody"];
        yield 'underline with three leading spaces' => ["Title\n   ---\nBody", "## Title\nBody"];
        yield 'underline with four leading spaces is not an underline' => ["Title\n    ---", "Title\n    ---"];
        yield 'heading text is trimmed' => ["  Title with spaces  \n===", '# Title with spaces'];
        yield 'heading at the start after a previous section' => ["# Intro\nText\n\nNext\n---\nMore", "# Intro\nText\n\n## Next\nMore"];
        yield 'heading directly after an atx heading' => ["# Intro\nSub\n---", "# Intro\n## Sub"];
        yield 'several headings' => ["One\n===\n\nTwo\n---\n\nThree\n---", "# One\n\n## Two\n\n## Three"];

        yield 'thematic break after a blank line' => ["Para\n\n---\n\nMore", "Para\n\n---\n\nMore"];
        yield 'thematic break at the top without front matter end' => ["---\nText", "---\nText"];
        yield 'equals line at the top' => ["===\nText", "===\nText"];
        yield 'atx heading followed by a rule' => ["# Title\n---", "# Title\n---"];
        yield 'atx heading untouched' => ["# Title\n\n## Sub\n\nBody", "# Title\n\n## Sub\n\nBody"];

        yield 'front matter' => ["---\ntitle: Page\nlang: en\n---\n\nBody", "---\ntitle: Page\nlang: en\n---\n\nBody"];
        yield 'front matter followed by a setext heading' => ["---\ntitle: Page\n---\nTitle\n===\nBody", "---\ntitle: Page\n---\n# Title\nBody"];

        yield 'equals inside a backtick fence' => ["```\nTitle\n====\n```", "```\nTitle\n====\n```"];
        yield 'dashes inside a tilde fence' => ["~~~\nTitle\n---\n~~~", "~~~\nTitle\n---\n~~~"];
        yield 'unterminated fence runs to the end' => ["```php\nTitle\n====\nSub\n---", "```php\nTitle\n====\nSub\n---"];
        yield 'shorter fence does not close a longer one' => ["````\n```\nTitle\n===\n````", "````\n```\nTitle\n===\n````"];
        yield 'other fence character does not close' => ["```\n~~~\nTitle\n===\n```", "```\n~~~\nTitle\n===\n```"];
        yield 'heading after a closed fence' => ["```\ncode\n```\nTitle\n===", "```\ncode\n```\n# Title"];

        yield 'table row followed by dashes' => ["| a | b |\n---", "| a | b |\n---"];
        yield 'table with a delimiter row' => ["a | b\n---|---\n1 | 2", "a | b\n---|---\n1 | 2"];
        yield 'dash list item followed by dashes' => ["- item\n---", "- item\n---"];
        yield 'star list item followed by equals' => ["* item\n===", "* item\n==="];
        yield 'ordered list item followed by dashes' => ["1. item\n---", "1. item\n---"];
        yield 'blockquote followed by dashes' => ["> quote\n---", "> quote\n---"];
        yield 'indented code followed by dashes' => ["    code\n---", "    code\n---"];
        yield 'tab-indented code followed by equals' => ["\tcode\n===", "\tcode\n==="];

        yield 'two-line paragraph followed by equals' => ["Line one\nLine two\n===", "Line one\nLine two\n==="];
        yield 'two-line paragraph followed by dashes' => ["Line one\nLine two\n---", "Line one\nLine two\n---"];

        yield 'crlf line endings' => ["Title\r\n=====\r\nBody\r\n", "# Title\nBody\n"];
        yield 'cr line endings' => ["Title\r---\rBody", "## Title\nBody"];
        yield 'empty input' => ['', ''];
        yield 'plain paragraph' => ['Just text.', 'Just text.'];
    }

    #[DataProvider('fixtures')]
    public function testNormalize(string $input, string $expected): void
    {
        self::assertSame($expected, SetextHeadingNormalizer::normalize($input));
    }

    #[DataProvider('fixtures')]
    public function testNormalizeIsIdempotent(string $input, string $expected): void
    {
        $once = SetextHeadingNormalizer::normalize($input);

        self::assertSame($once, SetextHeadingNormalizer::normalize($once));
    }

    public function testRealisticFirecrawlPage(): void
    {
        $page = "Company Handbook\n================\n\nWelcome to the handbook.\n\nOnboarding\n----------\n\nYour first day.\n\n| Day | Task |\n| --- | ---- |\n| 1 | Laptop |\n\n* * *\n\nHolidays\n--------\n\n```\nnot a heading\n-------------\n```\n";

        self::assertSame(
            "# Company Handbook\n\nWelcome to the handbook.\n\n## Onboarding\n\nYour first day.\n\n| Day | Task |\n| --- | ---- |\n| 1 | Laptop |\n\n* * *\n\n## Holidays\n\n```\nnot a heading\n-------------\n```\n",
            SetextHeadingNormalizer::normalize($page),
        );
    }
}
