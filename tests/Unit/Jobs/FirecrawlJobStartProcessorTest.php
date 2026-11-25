<?php

// file generated with AI assistance: Claude Code - 2026-10-03 00:10:23 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\Tests\Unit\Jobs;

use ApiPlatform\Metadata\Post;
use Dmstr\Firecrawl\Entity\Refs\FirecrawlJob;
use Dmstr\Firecrawl\Jobs\JobStarter;
use Dmstr\Firecrawl\State\FirecrawlJobStartProcessor;
use Dmstr\Firecrawl\Testing\FakeFirecrawlClientFactory;
use Dmstr\Firecrawl\Tests\Fixtures\FirecrawlSecrets;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Kernel-free checks of `POST /refs/firecrawl_jobs`: the body is validated
 * against the bundle's start.input.json, the connection is resolved
 * (implicit or explicit) before anything is stored or called.
 */
final class FirecrawlJobStartProcessorTest extends TestCase
{
    use InMemoryJobsTrait;

    private const string MAIN = 'https://fc.example.org';
    private const string BACKUP = 'https://fc-backup.example.org';

    private FakeFirecrawlClientFactory $fake;

    protected function setUp(): void
    {
        $this->fake = new FakeFirecrawlClientFactory();
    }

    public function testSchemaIsFoundForTheStartOperation(): void
    {
        $schema = self::inputBodyValidator()->validate(FirecrawlJob::OPERATION_START, '{"url": "https://example.org"}');

        self::assertSame(['url' => 'https://example.org'], $schema);
    }

    public function testImplicitConnectionAndDefaultTypeCrawl(): void
    {
        $main = $this->addConnection('main', self::MAIN);
        $this->addConnection('old', self::BACKUP, active: false);
        $this->fake->queue('POST', '/v2/crawl', ['success' => true, 'id' => 'crawl-1']);

        $job = $this->process('{"url": "https://example.org", "options": {"limit": 5}}');

        self::assertSame($main, $job->getApiConfiguration());
        self::assertSame(FirecrawlJob::TYPE_CRAWL, $job->getType());
        self::assertSame(FirecrawlJob::STATUS_SCRAPING, $job->getStatus());
        self::assertSame(['limit' => 5], $job->getOptions());
        self::assertSame(['url' => 'https://example.org', 'limit' => 5], $this->fake->getRequests(self::MAIN, 'POST', '/v2/crawl')[0]['body']);
    }

    public function testExplicitConnectionByNameAndIri(): void
    {
        $this->addConnection('main', self::MAIN);
        $backup = $this->addConnection('backup', self::BACKUP);
        $this->iris['/api/admin/api_configurations/'.$backup->getId()] = $backup;
        $this->fake->serve('POST', '/v2/scrape', ['success' => true, 'data' => ['markdown' => 'x']]);

        $byName = $this->process('{"type": "scrape", "url": "https://example.org", "apiConfiguration": "backup"}');
        $byIri = $this->process('{"type": "scrape", "url": "https://example.org", "apiConfiguration": "/api/admin/api_configurations/'.$backup->getId().'"}');

        self::assertSame($backup, $byName->getApiConfiguration());
        self::assertSame($backup, $byIri->getApiConfiguration());
        self::assertSame(FirecrawlJob::STATUS_COMPLETED, $byName->getStatus());
        self::assertSame(2, $this->fake->getRequestCount(self::BACKUP, 'POST', '/v2/scrape'));
        self::assertSame(0, $this->fake->getRequestCount(self::MAIN));
    }

    public function testMalformedJsonIsABadRequest(): void
    {
        $this->addConnection('main', self::MAIN);

        $this->expectException(BadRequestHttpException::class);

        $this->process('{"url":');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidBodies(): iterable
    {
        yield 'empty body (url missing)' => [''];
        yield 'url missing' => ['{"type": "crawl"}'];
        yield 'relative url' => ['{"url": "/docs"}'];
        yield 'ftp url' => ['{"url": "ftp://example.org"}'];
        yield 'unknown type' => ['{"url": "https://example.org", "type": "map"}'];
        yield 'options not an object' => ['{"url": "https://example.org", "options": [1]}'];
        yield 'unknown property' => ['{"url": "https://example.org", "limit": 5}'];
        yield 'empty connection' => ['{"url": "https://example.org", "apiConfiguration": ""}'];
    }

    #[DataProvider('invalidBodies')]
    public function testSchemaViolationIsUnprocessableAndStoresNothing(string $body): void
    {
        $this->addConnection('main', self::MAIN);

        try {
            $this->process($body);
            self::fail('Expected a 422.');
        } catch (UnprocessableEntityHttpException $e) {
            self::assertStringContainsString('Input validation failed for operation "firecrawl_job_start"', $e->getMessage());
        }

        self::assertSame([], $this->rows);
        self::assertSame(0, $this->fake->getClientCount());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function rejectedConnections(): iterable
    {
        yield 'unknown' => ['{"url": "https://example.org", "apiConfiguration": "missing"}', 'Unknown Firecrawl connection "missing"'];
        yield 'inactive' => ['{"url": "https://example.org", "apiConfiguration": "old"}', 'is inactive'];
    }

    #[DataProvider('rejectedConnections')]
    public function testRejectedConnectionStoresNothing(string $body, string $message): void
    {
        $this->addConnection('main', self::MAIN);
        $this->addConnection('old', self::BACKUP, active: false);

        try {
            $this->process($body);
            self::fail('Expected a 422.');
        } catch (UnprocessableEntityHttpException $e) {
            self::assertStringContainsString($message, $e->getMessage());
        }

        self::assertSame([], $this->rows);
        self::assertSame(0, $this->fake->getClientCount());
    }

    public function testNoActiveConnectionIsUnprocessable(): void
    {
        $this->addConnection('old', self::BACKUP, active: false);

        $this->expectException(UnprocessableEntityHttpException::class);
        $this->expectExceptionMessage('No active firecrawl connection');

        $this->process('{"url": "https://example.org"}');
    }

    private function process(string $body): FirecrawlJob
    {
        $request = Request::create('/api/refs/firecrawl_jobs', 'POST', content: $body);

        return $this->processor()->process(null, new Post(name: FirecrawlJob::OPERATION_START), [], ['request' => $request]);
    }

    private function processor(): FirecrawlJobStartProcessor
    {
        return new FirecrawlJobStartProcessor(
            self::inputBodyValidator(),
            $this->connectionResolver(),
            new JobStarter($this->entityManager(), $this->fake, FirecrawlSecrets::create(), new NullLogger()),
        );
    }
}
