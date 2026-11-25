<?php

// file generated with AI assistance: Claude Code - 2026-10-13 21:47:18 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\Onboarding;

/**
 * Finds the regions of a site that repeat on every page (navigation,
 * header, footer, cookie consent, …) in the raw HTML of a few sample pages,
 * and proposes them as Firecrawl `excludeTags` entries.
 *
 * Based on an earlier Python HTML analyzer, extended by a comparison across
 * pages:
 *
 *  - Category match (as in the original analyzer): a semantic tag (`nav`,
 *    `header`, `footer`, `aside`; with its id or first class in the
 *    selector, e.g. `header.site-head`, so a site header is told apart from
 *    a bare article `header`) or a class/id matching a boilerplate
 *    pattern (cookie/consent, navigation, footer, sidebar, language,
 *    contact widget, social). Only root containers count: the subtree of a
 *    match is not scanned further, because `excludeTags` removes the whole
 *    subtree anyway.
 *  - Repetition (new): an element with a class or id among the top levels
 *    of `body` whose normalised text is identical on at least two sample
 *    pages. Main content differs between pages; a block with the same text
 *    everywhere is boilerplate even without a telling name. Only the
 *    outermost repeated block of a branch is proposed.
 *
 * A candidate is preselected when it occurs on every sample page and its
 * text is identical everywhere, or when it is a strong category match
 * (cookie/consent, navigation, footer, language, social, contact widget,
 * `nav`, `footer`) on every page. With a single sample page there is no
 * comparison: every category match is preselected, `header` and `aside`
 * excepted, because both also occur inside article content.
 *
 * Pure: no I/O, the HTML comes from the caller.
 */
final class BoilerplateAnalyzer
{
    /** Always removed, never offered: they carry no readable content. */
    public const array ALWAYS_EXCLUDED = ['script', 'style', 'noscript'];

    public const array SEMANTIC_TAGS = ['nav', 'header', 'footer', 'aside'];

    /** Elements that are skipped with their subtree. */
    private const array SKIPPED_TAGS = ['script', 'style', 'noscript', 'template', 'svg', 'iframe', 'head'];

    /** @var array<string, string> category => pattern, in match order */
    private const array CATEGORY_PATTERNS = [
        'cookie/consent' => '/cookie|consent|gdpr|privacy.?banner|cc-banner|onetrust|cookiebot|usercentrics/i',
        'navigation' => '/\bnav\b|navbar|main.?nav|site.?nav|breadcrumb|\bmenu\b/i',
        'footer' => '/\bfoot(?:er)?\b|site.?footer|footer.?wrap|footer.?bottom/i',
        'sidebar' => '/sidebar|side.?bar|\bwidget\b|\baside\b/i',
        'language' => '/language.?selector|lang.?switch|locale.?switch|lang.?nav/i',
        'contact-widget' => '/contact.?widget|chat.?widget|live.?chat|direct.?contact/i',
        'social' => '/social.?share|share.?button|social.?media|social.?link/i',
    ];

    /** Categories (and semantic tags) that are boilerplate even when their text differs per page, e.g. a breadcrumb. */
    private const array STRONG = ['cookie/consent', 'navigation', 'footer', 'language', 'social', 'contact-widget', 'tag:nav', 'tag:footer'];

    /** Repetition is looked for this many element levels below `body`. */
    private const int REPEAT_MAX_DEPTH = 5;

    /** Shorter texts are too unspecific to count as a repeated block. */
    private const int REPEAT_MIN_TEXT = 20;

    private const int SAMPLE_LENGTH = 60;

    /** A class or id outside this pattern cannot be used as a plain CSS selector and is ignored. */
    private const string PLAIN_NAME = '/^-?[A-Za-z_][A-Za-z0-9_-]*$/';

    /**
     * @param list<array{url: string, html: string}> $pages raw HTML per sample page
     *
     * @return array{
     *     candidates: list<array{selector: string, category: string, tag: string, pages: int, identicalText: bool, sample: string, preselected: bool}>,
     *     alwaysExcluded: list<string>,
     *     pageCount: int
     * }
     */
    public function analyze(array $pages): array
    {
        /** @var array<string, array{category: string, tag: string, perPage: array<int, array{hash: string, sample: string}>, ancestors: array<string, true>, kind: string}> $found */
        $found = [];

        foreach ($pages as $index => $page) {
            $document = \Dom\HTMLDocument::createFromString($page['html'], \LIBXML_NOERROR | \LIBXML_COMPACT);
            $body = $document->body;
            if (null === $body) {
                continue;
            }
            $this->walk($body, 0, [], $index, $found);
        }

        $pageCount = \count($pages);
        $repeated = [];
        foreach ($found as $selector => $entry) {
            if ('repeated' === $entry['kind'] && self::identical($entry['perPage']) && \count($entry['perPage']) >= 2) {
                $repeated[$selector] = true;
            }
        }

        $candidates = [];
        foreach ($found as $selector => $entry) {
            $identical = self::identical($entry['perPage']);
            $pagesWith = \count($entry['perPage']);
            // Only repeated blocks with the same text; and nothing inside a
            // repeated block that is offered itself (outermost block only).
            if ('repeated' === $entry['kind'] && !isset($repeated[$selector])) {
                continue;
            }
            if ([] !== array_intersect_key($entry['ancestors'], $repeated)) {
                continue;
            }

            $everywhere = $pagesWith === $pageCount;
            $key = 'tag' === $entry['kind'] ? 'tag:'.$entry['tag'] : $entry['category'];
            $strong = \in_array($key, self::STRONG, true);
            $preselected = 1 === $pageCount
                ? $strong || ('category' === $entry['kind'] || 'tag' === $entry['kind']) && !\in_array($entry['tag'], ['header', 'aside'], true)
                : $everywhere && ($identical || $strong);

            $candidates[] = [
                'selector' => $selector,
                'category' => $entry['category'],
                'tag' => $entry['tag'],
                'pages' => $pagesWith,
                'identicalText' => $pagesWith > 1 && $identical,
                'sample' => reset($entry['perPage'])['sample'],
                'preselected' => $preselected,
            ];
        }

        // Preselected first, then by spread, then by selector, so the form is stable.
        usort($candidates, static fn (array $a, array $b): int => [$b['preselected'], $b['pages'], $a['selector']] <=> [$a['preselected'], $a['pages'], $b['selector']]);

        return [
            'candidates' => $candidates,
            'alwaysExcluded' => self::ALWAYS_EXCLUDED,
            'pageCount' => $pageCount,
        ];
    }

    /**
     * @param array<string, true> $ancestors repeated-block selectors above $element
     * @param array<string, mixed> $found
     */
    private function walk(\Dom\Element $element, int $depth, array $ancestors, int $page, array &$found): void
    {
        // Dom\Element::$children only exists from PHP 8.5 on.
        for ($child = $element->firstElementChild; null !== $child; $child = $child->nextElementSibling) {
            $tag = strtolower($child->localName);
            if (\in_array($tag, self::SKIPPED_TAGS, true)) {
                continue;
            }

            if (\in_array($tag, self::SEMANTIC_TAGS, true)) {
                // `header.site-head` apart from a bare article `header`: the
                // same tag is site chrome in one place and content in another.
                $selector = $tag.(self::plainSelector($child) ?? '');
                self::record($found, $selector, $tag, 'tag:'.$tag, 'tag', $child, $page, $ancestors);
                continue;
            }

            $match = self::categoryMatch($child);
            if (null !== $match) {
                self::record($found, $match[0], $tag, $match[1], 'category', $child, $page, $ancestors);
                continue;
            }

            $nextAncestors = $ancestors;
            if ($depth < self::REPEAT_MAX_DEPTH) {
                $selector = self::plainSelector($child);
                if (null !== $selector && mb_strlen(self::text($child)) >= self::REPEAT_MIN_TEXT) {
                    self::record($found, $selector, $tag, 'repeated', 'repeated', $child, $page, $ancestors);
                    $nextAncestors[$selector] = true;
                }
            }

            $this->walk($child, $depth + 1, $nextAncestors, $page, $found);
        }
    }

    /**
     * @param array<string, mixed> $found
     * @param array<string, true>  $ancestors
     */
    private static function record(array &$found, string $selector, string $tag, string $category, string $kind, \Dom\Element $element, int $page, array $ancestors): void
    {
        $found[$selector] ??= ['category' => $category, 'tag' => $tag, 'perPage' => [], 'ancestors' => [], 'kind' => $kind];
        $found[$selector]['ancestors'] += $ancestors;
        if (isset($found[$selector]['perPage'][$page])) {
            // The same selector twice on one page (e.g. two `nav`): the page
            // counts once, its text is the concatenation.
            $text = self::text($element);
            $found[$selector]['perPage'][$page]['hash'] = sha1($found[$selector]['perPage'][$page]['hash'].$text);

            return;
        }
        $text = self::text($element);
        $found[$selector]['perPage'][$page] = [
            'hash' => sha1($text),
            'sample' => mb_strlen($text) > self::SAMPLE_LENGTH ? mb_substr($text, 0, self::SAMPLE_LENGTH).'…' : $text,
        ];
    }

    /**
     * The first class (in order) or else the id that matches a category.
     *
     * @return array{0: string, 1: string}|null selector and category
     */
    private static function categoryMatch(\Dom\Element $element): ?array
    {
        $candidates = [];
        foreach (preg_split('/\s+/', trim((string) $element->getAttribute('class'))) ?: [] as $class) {
            if ('' !== $class && 1 === preg_match(self::PLAIN_NAME, $class)) {
                $candidates[] = ['.'.$class, $class];
            }
        }
        $id = trim((string) $element->getAttribute('id'));
        if ('' !== $id && 1 === preg_match(self::PLAIN_NAME, $id)) {
            $candidates[] = ['#'.$id, $id];
        }

        foreach ($candidates as [$selector, $name]) {
            foreach (self::CATEGORY_PATTERNS as $category => $pattern) {
                if (1 === preg_match($pattern, $name)) {
                    return [$selector, $category];
                }
            }
        }

        return null;
    }

    /** `#id` when there is a usable id, else the first usable class. */
    private static function plainSelector(\Dom\Element $element): ?string
    {
        $id = trim((string) $element->getAttribute('id'));
        if ('' !== $id && 1 === preg_match(self::PLAIN_NAME, $id)) {
            return '#'.$id;
        }
        foreach (preg_split('/\s+/', trim((string) $element->getAttribute('class'))) ?: [] as $class) {
            if ('' !== $class && 1 === preg_match(self::PLAIN_NAME, $class)) {
                return '.'.$class;
            }
        }

        return null;
    }

    /** Visible text with whitespace collapsed; scripts and styles inside do not count. */
    private static function text(\Dom\Element $element): string
    {
        $parts = [];
        self::collectText($element, $parts);

        return trim((string) preg_replace('/\s+/u', ' ', implode(' ', $parts)));
    }

    /**
     * @param list<string> $parts
     */
    private static function collectText(\Dom\Node $node, array &$parts): void
    {
        foreach ($node->childNodes as $child) {
            if ($child instanceof \Dom\Text) {
                $parts[] = $child->data;
            } elseif ($child instanceof \Dom\Element && !\in_array(strtolower($child->localName), self::SKIPPED_TAGS, true)) {
                self::collectText($child, $parts);
            }
        }
    }

    /**
     * @param array<int, array{hash: string, sample: string}> $perPage
     */
    private static function identical(array $perPage): bool
    {
        return 1 === \count(array_unique(array_column($perPage, 'hash')));
    }
}
