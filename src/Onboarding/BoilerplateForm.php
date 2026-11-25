<?php

// file generated with AI assistance: Claude Code - 2026-10-13 21:47:18 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\Onboarding;

/**
 * The multiple-choice step of the crawl onboarding: a JSON schema (rendered
 * as a checkbox list by Jedison: an array with `uniqueItems` and an `enum`
 * of items) plus the preselected value, built on the server from the
 * candidates of {@see BoilerplateAnalyzer}.
 *
 * The schema comes from the server, not from the model, so the choices a
 * user can tick are exactly the selectors found in the sample pages; a
 * selector injected through page content or invented by the model is not
 * among them. The agent passes `schema` and `value` through unchanged; the
 * client sends the submitted value back as the next user message.
 */
final class BoilerplateForm
{
    public const string FORM_ID = 'boilerplate';

    /**
     * @param list<array{selector: string, category: string, tag: string, pages: int, identicalText: bool, sample: string, preselected: bool}> $candidates
     *
     * @return array{id: string, schema: array<string, mixed>, value: array{exclude: list<string>, onlyMainContent: bool}}
     */
    public static function build(array $candidates, int $pageCount): array
    {
        $selectors = array_column($candidates, 'selector');
        $titles = array_map(static fn (array $c): string => self::title($c, $pageCount), $candidates);

        $exclude = [
            'type' => 'array',
            'title' => 'Remove these regions from every page',
            'description' => 'Found in the sample pages; preselected regions occur on every page. Script, style and noscript are always removed.',
            'uniqueItems' => true,
            'items' => ['type' => 'string', 'enum' => $selectors, 'x-enumTitles' => $titles],
        ];
        if ([] === $selectors) {
            // An enum must not be empty; without candidates there is nothing to tick.
            $exclude['items'] = ['type' => 'string'];
            $exclude['maxItems'] = 0;
            $exclude['description'] = 'No repeating regions found in the sample pages.';
        }

        return [
            'id' => self::FORM_ID,
            'schema' => [
                'type' => 'object',
                'title' => 'Boilerplate',
                'required' => ['exclude', 'onlyMainContent'],
                'additionalProperties' => false,
                'properties' => [
                    'exclude' => $exclude,
                    'onlyMainContent' => [
                        'type' => 'boolean',
                        'title' => 'Also let Firecrawl keep the main content only (onlyMainContent)',
                    ],
                ],
            ],
            'value' => [
                'exclude' => array_values(array_column(array_filter($candidates, static fn (array $c): bool => $c['preselected']), 'selector')),
                'onlyMainContent' => true,
            ],
        ];
    }

    /**
     * E.g. `nav · nav element · 3/3 pages, same text · "Home Products Service …"`.
     *
     * @param array{selector: string, category: string, tag: string, pages: int, identicalText: bool, sample: string, preselected: bool} $candidate
     */
    private static function title(array $candidate, int $pageCount): string
    {
        $what = match (true) {
            'repeated' === $candidate['category'] => 'repeated '.$candidate['tag'],
            str_starts_with($candidate['category'], 'tag:') => $candidate['tag'].' element',
            default => $candidate['category'].' ('.$candidate['tag'].')',
        };
        $spread = $candidate['pages'].'/'.$pageCount.' pages'.($candidate['identicalText'] ? ', same text' : '');
        $sample = '' === $candidate['sample'] ? '(no text)' : '"'.$candidate['sample'].'"';

        return $candidate['selector'].' · '.$what.' · '.$spread.' · '.$sample;
    }
}
