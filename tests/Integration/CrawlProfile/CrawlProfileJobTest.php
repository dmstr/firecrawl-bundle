<?php

// file generated with AI assistance: Claude Code - 2026-11-06 16:00:00 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\Tests\Integration\CrawlProfile;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use Dmstr\Firecrawl\Crawl\CrawlJobQueue;
use Dmstr\Firecrawl\JobProcessor\CrawlCommandJobProcessor;
use Dmstr\Firecrawl\Testing\FakeFirecrawlClientFactory;
use Dmstr\Firecrawl\Tests\Kernel\TestDatabase;
use Dmstr\SymfonyJobQueue\Entity\Job;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/**
 * The Discover and Crawl actions of a crawl profile: one background job per
 * profile at a time, and the job runs the crawl command, which writes
 * identical Markdown once. The test transport
 * is in memory, so queued jobs stay pending. Rows use the prefix
 * `test-crawl-job-` and are removed before and after each test.
 */
#[Group('database')]
final class CrawlProfileJobTest extends ApiTestCase
{
    protected static ?bool $alwaysBootKernel = true;

    private const string PREFIX = 'test-crawl-job-';

    protected function setUp(): void
    {
        self::cleanUp();
    }

    protected function tearDown(): void
    {
        self::cleanUp();
        parent::tearDown();
    }

    public function testOneJobPerProfileAtATime(): void
    {
        $client = static::createClient();
        $profile = $this->profile($client);

        $discover = $client->request('POST', $profile.'/discover')->toArray();
        self::assertResponseStatusCodeSame(201);
        self::assertSame([CrawlJobQueue::JOB_TYPE_DISCOVER, 'pending'], [$discover['type'], $discover['status']]);

        $again = $client->request('POST', $profile.'/discover')->toArray();
        self::assertSame($discover['id'], $again['id']);

        $client->request('POST', $profile.'/crawl');
        self::assertResponseStatusCodeSame(409);
    }

    public function testImportNeedsTheImportSection(): void
    {
        $client = static::createClient();
        $profile = $this->profile($client);

        $client->request('POST', $profile.'/import');
        self::assertResponseStatusCodeSame(422);
    }

    public function testAFailedCommandFailsTheJobWithItsErrorAndOutput(): void
    {
        $client = static::createClient();
        $profile = $this->profile($client);
        $job = $client->request('POST', $profile.'/crawl')->toArray();

        $entity = static::getContainer()->get('doctrine.orm.entity_manager')->find(Job::class, $job['id']);
        try {
            static::getContainer()->get(CrawlCommandJobProcessor::class)->process($entity);
            self::fail('The crawl without urls.json must fail.');
        } catch (UnrecoverableMessageHandlingException $e) {
            self::assertStringContainsString('No pages in', $e->getMessage());
        }
        self::assertSame('app:crawl:run', $entity->getResultData()['command']);
        self::assertStringContainsString('app:crawl:discover', $entity->getResultData()['output']);
    }

    public function testIdenticalMarkdownIsWrittenOnce(): void
    {
        $this->profile(static::createClient());
        $dir = self::dir();
        (new Filesystem())->dumpFile($dir.'/urls.json', json_encode(['pages' => ['https://site.example/de/a-1', 'https://site.example/en/a-1', 'https://site.example/b-2']], \JSON_THROW_ON_ERROR));
        $fake = static::getContainer()->get(FakeFirecrawlClientFactory::class);
        foreach (['Page A', 'Page A', 'Page B'] as $markdown) {
            $fake->queue('POST', '/v2/scrape', ['data' => ['markdown' => $markdown, 'metadata' => ['statusCode' => 200]]]);
        }

        $tester = new CommandTester((new Application(static::$kernel))->find('app:crawl:run'));
        $tester->execute(['profile' => self::PREFIX.'site', '--no-files' => true]);

        $tester->assertCommandIsSuccessful();
        self::assertCount(2, glob($dir.'/documents/*.md') ?: []);
        self::assertMatchesRegularExpression('/duplicates\s+1\s/', $tester->getDisplay());
    }

    private static function dir(): string
    {
        return static::getContainer()->getParameter('kernel.project_dir').'/var/crawl/'.self::PREFIX.'site';
    }

    /** IRI of a new profile on a new firecrawl connection. */
    private function profile(Client $client): string
    {
        $connection = $client->request('POST', '/api/admin/api_configurations', [
            'headers' => ['Content-Type' => 'application/ld+json'],
            'json' => ['name' => self::PREFIX.'firecrawl', 'active' => true, 'configJson' => ['firecrawl' => true, 'type' => 'firecrawl', 'endpoint_type' => 'rest', 'base_url' => 'http://firecrawl.example']],
        ])->toArray()['@id'];

        return (string) $client->request('POST', '/api/admin/crawl_profiles', [
            'headers' => ['Content-Type' => 'application/ld+json'],
            'json' => ['name' => self::PREFIX.'site', 'firecrawlConnection' => $connection, 'discover' => ['seeds' => ['https://site.example/']]],
        ])->toArray()['@id'];
    }

    private static function cleanUp(): void
    {
        $db = self::db();
        (new Filesystem())->remove(self::dir());
        $db->executeStatement("DELETE FROM dmstr_job WHERE input_data::jsonb->>'crawlProfile' IN (SELECT id::text FROM firecrawl_crawl_profile WHERE name LIKE :prefix)", ['prefix' => self::PREFIX.'%']);
        $db->executeStatement('DELETE FROM firecrawl_crawl_profile WHERE name LIKE :prefix', ['prefix' => self::PREFIX.'%']);
        $db->executeStatement('DELETE FROM dmstr_api_configuration WHERE name LIKE :prefix', ['prefix' => self::PREFIX.'%']);
        static::ensureKernelShutdown();
    }

    private static function db(): Connection
    {
        if (null === static::$kernel) {
            static::bootKernel();
        }
        TestDatabase::migrate(static::$kernel);

        /** @var Connection $connection */
        $connection = static::getContainer()->get('doctrine.dbal.default_connection');

        return $connection;
    }
}
