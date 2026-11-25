<?php

// file generated with AI assistance: Claude Code - 2026-11-06 14:10:00 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\Tests\Integration\CrawlProfile;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * The URL list of the latest discover under var/crawl/<profile>/ as the
 * read-only resource /api/admin/crawl_url_lists (the test firewall
 * authenticates as admin). The fixture profile is written before
 * and removed after each test.
 */
final class CrawlOutputResourceTest extends ApiTestCase
{
    protected static ?bool $alwaysBootKernel = true;

    private const string PROFILE = 'test-crawl-output';

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = static::getContainer()->getParameter('kernel.project_dir').'/var/crawl/'.self::PROFILE;
        $fs = new Filesystem();
        $fs->dumpFile($this->dir.'/urls.json', json_encode([
            'seeds' => ['https://site.example/'],
            'pages' => ['https://site.example/', 'https://site.example/about'],
            'files' => [['url' => 'https://site.example/a.pdf', 'text' => 'Catalogue']],
            'broken' => [['status' => 404, 'url' => 'https://site.example/gone', 'linkedFrom' => 'https://site.example/about']],
        ], \JSON_THROW_ON_ERROR));
        static::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->dir);
        parent::tearDown();
    }

    public function testTheUrlListOfTheLatestDiscoverIsOneEntryWithTheWholeFile(): void
    {
        $client = static::createClient();

        $lists = $client->request('GET', '/api/admin/crawl_url_lists')->toArray()['hydra:member'];
        $mine = array_values(array_filter($lists, static fn (array $list): bool => self::PROFILE === $list['profile']));
        self::assertCount(1, $mine);
        self::assertSame([2, 1, 1], [$mine[0]['pages'], $mine[0]['files'], $mine[0]['broken']]);
        self::assertArrayNotHasKey('urls', $mine[0]);

        $one = $client->request('GET', $mine[0]['@id'])->toArray();
        $file = json_decode($one['urls'], true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(['https://site.example/', 'https://site.example/about'], $file['pages']);
        self::assertSame('https://site.example/about', $file['broken'][0]['linkedFrom']);
    }
}
