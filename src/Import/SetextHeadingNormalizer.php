<?php

// file generated with AI assistance: Claude Code - 2026-10-03 07:31:09 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\Import;

/**
 * Rewrites setext headings (a text line underlined with `===` or `---`) to
 * ATX headings (`# ` and `## `), so the Markdown chunker, which splits at
 * ATX headings only, sees the sections of crawled pages.
 *
 * A line is rewritten when all of these hold:
 *  - it consists of `=` (H1) or `-` (H2) characters only, with up to three
 *    leading spaces and optional trailing whitespace;
 *  - it follows directly a single non-blank paragraph line, i.e. the line
 *    before that one is blank, the start of the document, the end of the
 *    front matter, an ATX heading or a closing code fence;
 *  - that paragraph line is not an ATX heading, list item (`-`, `*`, `+`,
 *    `1.`, `1)`), blockquote (`>`), table row (contains `|`), indented code
 *    (four spaces or a tab), fence line or a line of `=`/`-`/`*`/`_` only.
 *
 * The heading text is trimmed and the underline line removed. A paragraph
 * of several lines followed by an underline is left untouched on purpose
 * (conservative; CommonMark would turn the whole paragraph into a heading).
 * `---` and `===` after a blank line or at the top stay (thematic break).
 * Fenced code blocks (``` and ~~~, closed by a fence of the same character
 * and at least the same length, as in the neuron-ai-bundle's
 * `MarkdownChunker`; an unterminated fence runs to the end) and a YAML front
 * matter block at the very top (`---` on line 1 up to the next `---` line)
 * are copied verbatim.
 *
 * Line endings are normalised to `\n` first. The function is idempotent:
 * `normalize(normalize($x)) === normalize($x)`.
 */
final class SetextHeadingNormalizer
{
    private const string UNDERLINE = '/^ {0,3}(=+|-+)[ \t]*$/';
    private const string FENCE_OPEN = '/^ {0,3}(`{3,}|~{3,})/';
    private const string ATX = '/^ {0,3}#{1,6}(?:[ \t]|$)/';
    private const string LIST_ITEM = '/^ {0,3}(?:[-*+]|\d{1,9}[.)])(?:[ \t]|$)/';
    private const string BLOCKQUOTE = '/^ {0,3}>/';
    private const string INDENTED_CODE = '/^(?: {4}|\t)/';
    private const string RULE_LIKE = '/^ {0,3}[-=*_ \t]+$/';
    private const string FRONT_MATTER_DELIMITER = '/^---[ \t]*$/';

    public static function normalize(string $markdown): string
    {
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $markdown));
        $count = \count($lines);

        $out = [];
        $start = 0;

        if ($count > 1 && 1 === preg_match(self::FRONT_MATTER_DELIMITER, $lines[0])) {
            for ($j = 1; $j < $count; ++$j) {
                if (1 === preg_match(self::FRONT_MATTER_DELIMITER, $lines[$j])) {
                    $out = \array_slice($lines, 0, $j + 1);
                    $start = $j + 1;
                    break;
                }
            }
        }

        $fence = null;
        // Index in $out of a single-line paragraph that an underline may turn into a heading.
        $candidate = null;
        // The previous output line ends a block (start, blank line, front matter, ATX heading, closing fence).
        $boundary = true;

        for ($i = $start; $i < $count; ++$i) {
            $line = $lines[$i];

            if (null !== $fence) {
                $out[] = $line;
                if (self::closesFence($line, $fence)) {
                    $fence = null;
                    $boundary = true;
                }
                continue;
            }

            $opened = self::opensFence($line);
            if (null !== $opened) {
                $out[] = $line;
                $fence = $opened;
                $candidate = null;
                $boundary = false;
                continue;
            }

            if (null !== $candidate && 1 === preg_match(self::UNDERLINE, $line, $match)) {
                $out[$candidate] = ('=' === $match[1][0] ? '# ' : '## ').trim($out[$candidate]);
                $candidate = null;
                $boundary = true;
                continue;
            }

            $out[] = $line;

            if ('' === trim($line)) {
                $candidate = null;
                $boundary = true;
                continue;
            }

            if ($boundary && self::isParagraphLine($line)) {
                $candidate = \count($out) - 1;
                $boundary = false;
                continue;
            }

            $candidate = null;
            $boundary = 1 === preg_match(self::ATX, $line);
        }

        return implode("\n", $out);
    }

    private static function isParagraphLine(string $line): bool
    {
        return 1 !== preg_match(self::ATX, $line)
            && 1 !== preg_match(self::LIST_ITEM, $line)
            && 1 !== preg_match(self::BLOCKQUOTE, $line)
            && 1 !== preg_match(self::INDENTED_CODE, $line)
            && 1 !== preg_match(self::RULE_LIKE, $line)
            && !str_contains($line, '|');
    }

    private static function opensFence(string $line): ?string
    {
        return 1 === preg_match(self::FENCE_OPEN, $line, $match) ? $match[1] : null;
    }

    private static function closesFence(string $line, string $fence): bool
    {
        $pattern = \sprintf('/^ {0,3}%s{%d,}[ \t]*$/', preg_quote($fence[0], '/'), \strlen($fence));

        return 1 === preg_match($pattern, $line);
    }
}
