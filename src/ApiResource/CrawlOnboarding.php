<?php

// file generated with AI assistance: Claude Code - 2026-10-13 21:47:18 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\McpTool;
use Dmstr\Firecrawl\ApiResource\Onboarding\BoilerplateResult;
use Dmstr\Firecrawl\ApiResource\Onboarding\ReplyInput;
use Dmstr\Firecrawl\ApiResource\Onboarding\SampleAnalyzeInput;
use Dmstr\Firecrawl\ApiResource\Onboarding\SampleStartInput;
use Dmstr\Firecrawl\ApiResource\Onboarding\SampleStartResult;
use Dmstr\Firecrawl\ApiResource\Onboarding\SiteMapInput;
use Dmstr\Firecrawl\ApiResource\Onboarding\SiteMapResult;
use Dmstr\Firecrawl\Security\FirecrawlPermissions;
use Dmstr\Firecrawl\State\Onboarding\ReplyProcessor;
use Dmstr\Firecrawl\State\Onboarding\SampleAnalyzeProcessor;
use Dmstr\Firecrawl\State\Onboarding\SampleStartProcessor;
use Dmstr\Firecrawl\State\Onboarding\SiteMapProcessor;

/**
 * The read-only tools of the crawl onboarding as MCP tools (no HTTP
 * operation), served through dmstr/mcp-endpoint-bundle at `/mcp/{slug}`,
 * e.g. an endpoint `crawl-onboarding` that allows these tools.
 *
 * `crawl_sample_start`, `crawl_sample_analyze` and `crawl_map_site` contact
 * Firecrawl but store nothing, neither in the application nor as a Firecrawl job reference; they return
 * aggregates, never page HTML. `crawl_onboarding_reply` is the terminal
 * tool through which the agent gives its answer
 * ({@see ReplyProcessor}). All require the permission `firecrawl:onboarding:use`.
 *
 * Descriptions are one line and name the returned fields, because Neuron
 * ignores `structuredContent` and the output schema.
 */
#[ApiResource(
    operations: [],
    mcp: [
        self::TOOL_SAMPLE_START => new McpTool(
            name: self::TOOL_SAMPLE_START,
            description: 'Start the Firecrawl job that fetches sample pages of a site: one URL starts a crawl of a few pages from it (limit), several URLs a scrape of exactly these pages. Returns status (started, or failed: see error), jobId and kind; pass jobId and kind to crawl_sample_analyze.',
            annotations: ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => true],
            security: "is_granted('".FirecrawlPermissions::ONBOARDING_USE."')",
            validate: true,
            input: SampleStartInput::class,
            output: SampleStartResult::class,
            processor: SampleStartProcessor::class,
            outputFormats: self::OUTPUT_FORMATS,
        ),
        self::TOOL_SAMPLE_ANALYZE => new McpTool(
            name: self::TOOL_SAMPLE_ANALYZE,
            description: 'Wait for the sample job of crawl_sample_start and find the page regions that repeat across the pages (navigation, header, footer, cookie consent, sidebars). Returns status (completed; pending: call again with the same jobId and kind; failed: see error), jobId, total and completed (pages), pages (url, statusCode, title, error), candidates (selector, category, tag, pages, identicalText, sample, preselected), alwaysExcluded, form (id, schema, value: the multiple-choice form; in the answer name it as {"id": "boilerplate"}, the answer tool fills in schema and value) and error.',
            annotations: ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => true],
            security: "is_granted('".FirecrawlPermissions::ONBOARDING_USE."')",
            validate: true,
            input: SampleAnalyzeInput::class,
            output: BoilerplateResult::class,
            processor: SampleAnalyzeProcessor::class,
            outputFormats: self::OUTPUT_FORMATS,
        ),
        self::TOOL_MAP_SITE => new McpTool(
            name: self::TOOL_MAP_SITE,
            description: 'List the URLs of a site through Firecrawl (sitemap and links, nothing scraped) and summarise them. Returns total, limitReached, hosts (URL count per host), languages (prefix, count), sections (path prefix of up to two segments, count; the most frequent first), sampleUrls and error (set when Firecrawl failed).',
            annotations: ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => true],
            security: "is_granted('".FirecrawlPermissions::ONBOARDING_USE."')",
            validate: true,
            input: SiteMapInput::class,
            output: SiteMapResult::class,
            processor: SiteMapProcessor::class,
            outputFormats: self::OUTPUT_FORMATS,
        ),
        self::TOOL_REPLY => new McpTool(
            name: self::TOOL_REPLY,
            description: 'Give your answer to the operator: call it exactly once as the last action of every turn, with step, message, form (omit when nothing is to be chosen; {"id": "boilerplate"} for the form of crawl_sample_analyze, filled in from plan.sampleJob), plan and openQuestions; the turn ends with it. Returns the answer.',
            annotations: ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false],
            security: "is_granted('".FirecrawlPermissions::ONBOARDING_USE."')",
            validate: true,
            input: ReplyInput::class,
            processor: ReplyProcessor::class,
            outputFormats: self::OUTPUT_FORMATS,
            normalizationContext: ['preserve_empty_objects' => true],
        ),
    ],
)]
final class CrawlOnboarding
{
    public const string TOOL_SAMPLE_START = 'crawl_sample_start';

    public const string TOOL_SAMPLE_ANALYZE = 'crawl_sample_analyze';

    public const string TOOL_MAP_SITE = 'crawl_map_site';

    public const string TOOL_REPLY = 'crawl_onboarding_reply';

    /** Plain JSON, without the JSON-LD keys: the text is what the model reads, and the reply becomes the answer. */
    private const array OUTPUT_FORMATS = ['json' => ['application/json']];
}
// - revised 2026-10-14
