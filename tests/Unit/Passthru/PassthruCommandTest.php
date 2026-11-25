<?php

// file generated with AI assistance: Claude Code - 2026-10-03 00:09:10 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\Tests\Unit\Passthru;

use Dmstr\Firecrawl\Client\FirecrawlApiException;
use Dmstr\Firecrawl\Command\AbstractPassthruCommand;
use Dmstr\Firecrawl\Command\BatchScrapeCommand;
use Dmstr\Firecrawl\Command\CrawlCommand;
use Dmstr\Firecrawl\Command\MapCommand;
use Dmstr\Firecrawl\Command\ScrapeCommand;
use Dmstr\Firecrawl\Passthru\Operation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Kernel-free test of the four console twins over the real PassthruService
 * with the fake client factory (see PassthruFixturesTrait): arguments and
 * `--body` become the forwarded body, `--connection` selects the
 * connection, the answer is printed as pretty JSON, failures exit with 1.
 */
final class PassthruCommandTest extends TestCase
{
    use PassthruFixturesTrait;

    protected function setUp(): void
    {
        $this->setUpPassthru();
        $this->stored = [self::connection('default', self::DEFAULT_URL), self::connection('staging', self::STAGING_URL)];
    }

    /**
     * @return iterable<string, array{class-string<AbstractPassthruCommand>, Operation, array<string, mixed>}>
     */
    public static function commands(): iterable
    {
        yield 'scrape' => [ScrapeCommand::class, Operation::Scrape, ['url' => 'https://example.org']];
        yield 'map' => [MapCommand::class, Operation::Map, ['url' => 'https://example.org']];
        yield 'crawl' => [CrawlCommand::class, Operation::Crawl, ['url' => 'https://example.org']];
        yield 'batch scrape' => [BatchScrapeCommand::class, Operation::BatchScrape, ['urls' => ['https://example.org/a', 'https://example.org/b']]];
    }

    /**
     * @param class-string<AbstractPassthruCommand> $class
     * @param array<string, mixed>                  $arguments
     */
    #[DataProvider('commands')]
    public function testArgumentsBecomeTheBodyAndTheAnswerIsPrettyJson(string $class, Operation $operation, array $arguments): void
    {
        $answer = ['success' => true, 'id' => 'job-1', 'url' => 'https://fc-staging.example.org/v2/crawl/job-1'];
        $this->fake->queue('POST', $operation->remotePath(), $answer);
        $command = new $class($this->service());
        self::assertSame($operation->commandName(), $command->getName());

        $tester = new CommandTester($command);
        self::assertSame(Command::SUCCESS, $tester->execute($arguments + ['--connection' => 'staging']));
        self::assertSame($answer, json_decode($tester->getDisplay(), true));

        $requests = $this->fake->getRequests();
        self::assertCount(1, $requests);
        self::assertSame(self::STAGING_URL, $requests[0]['baseUrl']);
        self::assertSame($arguments, $requests[0]['body']);
        self::assertSame('cli', $this->logger->records[0]['context']['user']);
    }

    public function testOutputIsPrettyJsonOnly(): void
    {
        $answer = ['success' => true, 'data' => ['markdown' => "# Hällo\n", 'metadata' => ['sourceURL' => 'https://example.org/']]];
        $this->fake->serve('POST', '/v2/scrape', $answer);
        $tester = $this->tester(ScrapeCommand::class);

        $tester->execute(['url' => 'https://example.org', '--connection' => 'default']);

        self::assertSame(json_encode($answer, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE)."\n", $tester->getDisplay());
    }

    public function testBodyOptionIsMergedAndTheArgumentWins(): void
    {
        $this->fake->serve('POST', '/v2/crawl', ['success' => true, 'id' => 'job-1']);

        $exit = $this->tester(CrawlCommand::class)->execute([
            'url' => 'https://example.org',
            '--connection' => 'default',
            '--body' => '{"url": "https://ignored.example.org", "limit": 3, "scrapeOptions": {"formats": ["markdown"]}, "webhook": {}}',
        ]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertSame(
            '{"url":"https://example.org","limit":3,"scrapeOptions":{"formats":["markdown"]},"webhook":{}}',
            json_encode($this->fake->getRequests()[0]['body'], \JSON_UNESCAPED_SLASHES),
        );
    }

    public function testBatchUrlsArgumentWinsOverUrlsInTheBody(): void
    {
        $this->fake->serve('POST', '/v2/batch/scrape', ['success' => true, 'id' => 'b-1']);

        $this->tester(BatchScrapeCommand::class)->execute([
            'urls' => ['https://example.org/a'],
            '--connection' => 'default',
            '--body' => '{"urls": ["https://ignored.example.org"], "formats": ["markdown"]}',
        ]);

        self::assertSame(['urls' => ['https://example.org/a'], 'formats' => ['markdown']], $this->fake->getRequests()[0]['body']);
    }

    public function testConnectionReferenceInTheBodyIsUsedWithoutTheOption(): void
    {
        $this->fake->serve('POST', '/v2/map', ['success' => true, 'links' => []]);

        $exit = $this->tester(MapCommand::class)->execute(['url' => 'https://example.org', '--body' => '{"apiConfiguration": "staging"}']);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertSame(1, $this->fake->getRequestCount(self::STAGING_URL));
        self::assertSame(['url' => 'https://example.org'], $this->fake->getRequests()[0]['body']);
    }

    public function testAmbiguousImplicitConnectionFails(): void
    {
        $tester = $this->tester(ScrapeCommand::class);

        self::assertSame(Command::FAILURE, $tester->execute(['url' => 'https://example.org']));
        self::assertStringContainsString('Several active firecrawl connections', $tester->getDisplay());
        self::assertSame(0, $this->fake->getClientCount());
    }

    public function testRemoteFailureExitsWithOneAndTheMessage(): void
    {
        $this->fake->queueException('POST', '/v2/scrape', new FirecrawlApiException('Firecrawl request failed with HTTP 500', 500, 'boom'));
        $tester = $this->tester(ScrapeCommand::class);

        self::assertSame(Command::FAILURE, $tester->execute(['url' => 'https://example.org', '--connection' => 'default']));
        self::assertStringContainsString('Firecrawl scrape failed: HTTP 500: boom', $tester->getDisplay());
    }

    public function testInvalidUrlExitsWithOne(): void
    {
        $tester = $this->tester(ScrapeCommand::class);

        self::assertSame(Command::FAILURE, $tester->execute(['url' => 'example.org', '--connection' => 'default']));
        self::assertStringContainsString('Input validation failed', $tester->getDisplay());
        self::assertSame(0, $this->fake->getClientCount());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidBodies(): iterable
    {
        yield 'malformed' => ['{"limit": ', '--body is not valid JSON'];
        yield 'list' => ['["markdown"]', '--body must be a JSON object'];
        yield 'scalar' => ['42', '--body must be a JSON object'];
    }

    #[DataProvider('invalidBodies')]
    public function testInvalidBodyOptionExitsWithOne(string $body, string $message): void
    {
        $tester = $this->tester(ScrapeCommand::class);

        self::assertSame(Command::FAILURE, $tester->execute(['url' => 'https://example.org', '--body' => $body]));
        self::assertStringContainsString($message, $tester->getDisplay());
        self::assertSame(0, $this->fake->getClientCount());
    }

    /**
     * @param class-string<AbstractPassthruCommand> $class
     */
    private function tester(string $class): CommandTester
    {
        return new CommandTester(new $class($this->service()));
    }
}
