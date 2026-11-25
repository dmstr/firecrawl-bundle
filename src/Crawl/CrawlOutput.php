<?php

// file generated with AI assistance: Claude Code - 2026-11-06 14:00:00 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\Crawl;

use Dmstr\Firecrawl\ApiResource\CrawlUrlList;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Reads the latest URL list of `app:crawl:discover` under
 * `var/crawl/<profile>/urls.json`. Only the latest result exists, a new
 * discover overwrites it; nothing of it is stored in the database.
 */
final class CrawlOutput
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    /** @return list<string> the profiles that have output, by name */
    public function profiles(): array
    {
        $dirs = glob($this->base().'/*', \GLOB_ONLYDIR) ?: [];

        return array_values(array_filter(array_map('basename', $dirs), static fn (string $name): bool => 1 === preg_match('/^[a-z0-9][a-z0-9-]*$/', $name)));
    }

    /** The URL list of the latest discover with its counts, or null when there is none. */
    public function urlList(string $profile, bool $withFile = false): ?CrawlUrlList
    {
        $path = $this->base().'/'.basename($profile).'/urls.json';
        if (!is_file($path)) {
            return null;
        }
        $data = $this->json($profile, 'urls.json');
        $list = new CrawlUrlList(
            $profile,
            new \DateTimeImmutable('@'.filemtime($path)),
            \count($data['pages'] ?? []),
            \count($data['files'] ?? []),
            \count($data['broken'] ?? []),
        );
        if ($withFile) {
            $list->urls = json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE) ?: null;
        }

        return $list;
    }

    private function base(): string
    {
        return $this->projectDir.'/var/crawl';
    }

    /** @return array<mixed> */
    private function json(string $profile, string $file): array
    {
        $path = $this->base().'/'.basename($profile).'/'.$file;
        $data = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

        return \is_array($data) ? $data : [];
    }
}
