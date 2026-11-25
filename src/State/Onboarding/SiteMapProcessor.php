<?php

// file generated with AI assistance: Claude Code - 2026-10-13 21:47:18 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\State\Onboarding;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use Dmstr\Firecrawl\ApiResource\CrawlOnboarding;
use Dmstr\Firecrawl\ApiResource\Onboarding\SiteMapInput;
use Dmstr\Firecrawl\ApiResource\Onboarding\SiteMapResult;
use Dmstr\Firecrawl\Client\FirecrawlClientInterface;
use Dmstr\Firecrawl\Onboarding\OnboardingClient;
use Dmstr\Firecrawl\Onboarding\OnboardingFailure;

/**
 * MCP tool `crawl_map_site`: one Firecrawl map call (sitemap included),
 * summarised into hosts, language prefixes and path sections, the input
 * for proposing topics.
 *
 * @implements ProcessorInterface<SiteMapInput, SiteMapResult>
 */
final readonly class SiteMapProcessor implements ProcessorInterface
{
    public const int MAX_SECTIONS = 30;

    public const int MAX_SAMPLES = 15;

    /** `de`, `en`, `de-de`, `en-us`, `pt_br`. */
    private const string LANGUAGE_SEGMENT = '/^[a-z]{2}(?:[-_][a-z]{2})?$/';

    public function __construct(private OnboardingClient $client)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): SiteMapResult
    {
        if (!$data instanceof SiteMapInput) {
            throw new \InvalidArgumentException('Expected SiteMapInput payload');
        }

        $url = (string) $data->url;
        $limit = \is_int($data->limit) ? $data->limit : SiteMapInput::DEFAULT_LIMIT;

        try {
            $answer = $this->client->run(
                CrawlOnboarding::TOOL_MAP_SITE,
                \is_string($data->connection) ? $data->connection : null,
                [$url],
                static fn (FirecrawlClientInterface $client): array => $client->map(['url' => $url, 'limit' => $limit, 'sitemap' => 'include']),
            );
        } catch (OnboardingFailure $e) {
            return new SiteMapResult(0, false, error: $e->getMessage());
        }

        return self::summarise(self::urls($answer), $limit);
    }

    /**
     * @param list<string> $urls
     */
    public static function summarise(array $urls, int $limit): SiteMapResult
    {
        $hosts = [];
        $languages = [];
        $sections = [];
        $firstPerSection = [];
        foreach ($urls as $url) {
            $parts = parse_url($url);
            if (false === $parts || !isset($parts['host'])) {
                continue;
            }
            $hosts[$parts['host']] = ($hosts[$parts['host']] ?? 0) + 1;

            $segments = array_values(array_filter(explode('/', $parts['path'] ?? ''), static fn (string $s): bool => '' !== $s));
            if (isset($segments[0]) && 1 === preg_match(self::LANGUAGE_SEGMENT, strtolower($segments[0]))) {
                $prefix = strtolower($segments[0]);
                $languages[$prefix] = ($languages[$prefix] ?? 0) + 1;
            }
            // Directories only: the last segment is the page itself.
            $directories = \array_slice($segments, 0, max(0, \count($segments) - 1));
            for ($depth = 1; $depth <= min(2, \count($directories)); ++$depth) {
                $path = '/'.implode('/', \array_slice($directories, 0, $depth)).'/';
                $sections[$path] = ($sections[$path] ?? 0) + 1;
                $firstPerSection[$path] ??= $url;
            }
        }

        arsort($hosts);
        arsort($languages);
        arsort($sections);
        $topSections = \array_slice($sections, 0, self::MAX_SECTIONS, true);

        return new SiteMapResult(
            \count($urls),
            \count($urls) >= $limit,
            $hosts,
            array_map(static fn (string $p, int $c): array => ['prefix' => $p, 'count' => $c], array_keys($languages), array_values($languages)),
            array_map(static fn (string $p, int $c): array => ['path' => $p, 'count' => $c], array_map('strval', array_keys($topSections)), array_values($topSections)),
            \array_slice(array_values(array_unique(array_intersect_key($firstPerSection, $topSections))), 0, self::MAX_SAMPLES),
        );
    }

    /**
     * v2 answers `links` as objects `{url, title, description}`; plain
     * strings (v1) are accepted as well.
     *
     * @param array<string, mixed> $answer
     *
     * @return list<string>
     */
    private static function urls(array $answer): array
    {
        $urls = [];
        foreach (\is_array($answer['links'] ?? null) ? $answer['links'] : [] as $link) {
            $url = \is_array($link) ? ($link['url'] ?? null) : $link;
            if (\is_string($url) && '' !== $url) {
                $urls[] = $url;
            }
        }

        return array_values(array_unique($urls));
    }
}
