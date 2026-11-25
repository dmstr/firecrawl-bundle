<?php

// file generated with AI assistance: Claude Code - 2026-10-03 00:10:23 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\Tests\Unit\Jobs;

use ApiPlatform\Metadata\Post;
use Dmstr\Firecrawl\Entity\Refs\FirecrawlJob;
use Dmstr\Firecrawl\State\FirecrawlJobScanProcessor;
use Dmstr\SymfonyJobQueue\Entity\Job;
use Dmstr\SymfonyJobQueue\Service\JobQueueService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Kernel-free checks of `POST /refs/firecrawl_jobs/scan`: the body is
 * validated against the bundle's scan.input.json and the connection is
 * resolved to its UUID before the job is queued with type and operation
 * `firecrawl_job_scan`.
 */
final class FirecrawlJobScanProcessorTest extends TestCase
{
    use InMemoryJobsTrait;

    private JobQueueService&MockObject $jobQueue;

    protected function setUp(): void
    {
        $this->jobQueue = $this->createMock(JobQueueService::class);
    }

    public function testScanSchemaHasOnlyTheOptionalConnection(): void
    {
        $this->jobQueue->expects(self::never())->method('createAndDispatch');

        self::assertSame([], self::inputBodyValidator()->validate(FirecrawlJob::OPERATION_SCAN, ''));
        self::assertSame(['apiConfiguration' => 'main'], self::inputBodyValidator()->validate(FirecrawlJob::OPERATION_SCAN, '{"apiConfiguration": "main"}'));
    }

    public function testEmptyBodyQueuesAScanOfAllConnections(): void
    {
        $job = new Job();
        $this->jobQueue->expects(self::once())->method('createAndDispatch')
            ->with('firecrawl_job_scan', [], 'firecrawl_job_scan')
            ->willReturn($job);

        self::assertSame($job, $this->process(''));
    }

    public function testConnectionIsResolvedToItsUuid(): void
    {
        $main = $this->addConnection('main', 'https://fc.example.org');
        $this->iris['/api/admin/api_configurations/'.$main->getId()] = $main;
        $this->jobQueue->expects(self::exactly(3))->method('createAndDispatch')
            ->with('firecrawl_job_scan', ['apiConfiguration' => (string) $main->getId()], 'firecrawl_job_scan')
            ->willReturn(new Job());

        $this->process('{"apiConfiguration": "main"}');
        $this->process('{"apiConfiguration": "/api/admin/api_configurations/'.$main->getId().'"}');
        $this->process('{"apiConfiguration": "'.$main->getId().'"}');
    }

    /**
     * @return iterable<string, array{string, class-string<\Throwable>}>
     */
    public static function rejectedBodies(): iterable
    {
        yield 'malformed json' => ['{"apiConfiguration":', BadRequestHttpException::class];
        yield 'unknown property' => ['{"connection": "main"}', UnprocessableEntityHttpException::class];
        yield 'number' => ['{"apiConfiguration": 42}', UnprocessableEntityHttpException::class];
        yield 'empty string' => ['{"apiConfiguration": ""}', UnprocessableEntityHttpException::class];
        yield 'list' => ['[]', UnprocessableEntityHttpException::class];
        yield 'unknown connection' => ['{"apiConfiguration": "missing"}', UnprocessableEntityHttpException::class];
        yield 'inactive connection' => ['{"apiConfiguration": "old"}', UnprocessableEntityHttpException::class];
        yield 'mcp connection' => ['{"apiConfiguration": "/api/admin/api_configurations/docs"}', UnprocessableEntityHttpException::class];
    }

    /**
     * @param class-string<\Throwable> $exception
     */
    #[DataProvider('rejectedBodies')]
    public function testRejectedBodyQueuesNoJob(string $body, string $exception): void
    {
        $this->addConnection('main', 'https://fc.example.org');
        $this->addConnection('old', 'https://old.example.org', active: false);
        $this->iris['/api/admin/api_configurations/docs'] = $this->addConnection('docs', 'https://docs.example.org/mcp', type: 'mcp');
        $this->jobQueue->expects(self::never())->method('createAndDispatch');

        $this->expectException($exception);

        $this->process($body);
    }

    private function process(string $body): Job
    {
        $request = Request::create('/api/refs/firecrawl_jobs/scan', 'POST', content: $body);
        $processor = new FirecrawlJobScanProcessor(self::inputBodyValidator(), $this->connectionResolver(), $this->jobQueue);

        return $processor->process(null, new Post(name: FirecrawlJob::OPERATION_SCAN), [], ['request' => $request]);
    }
}
