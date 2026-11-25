<?php

// file generated with AI assistance: Claude Code - 2026-10-31 19:00:00 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\Security;

/**
 * The permissions this bundle checks. Each one is registered at the container
 * tag "za7_core.permission" (config/services.yaml) and decided by the
 * permission voter of the host application; which roles hold it is the application's map
 * (`za7_core.permissions`), not the bundle's business.
 */
final class FirecrawlPermissions
{
    /** The four synchronous pass-through operations (scrape, map, crawl, batch scrape). */
    public const string PASSTHRU_EXECUTE = 'firecrawl:passthru:execute';

    /** Reading the job reference table. */
    public const string JOB_READ = 'firecrawl:job:read';

    /** Starting, syncing, scanning and importing jobs (the import writes documents). */
    public const string JOB_WRITE = 'firecrawl:job:write';

    /** The crawl onboarding tools. */
    public const string ONBOARDING_USE = 'firecrawl:onboarding:use';

    /** Reading and writing crawl profiles, which hold a site's login. */
    public const string PROFILE_MANAGE = 'firecrawl:profile:manage';

    /** @var list<string> */
    public const array ALL = [self::PASSTHRU_EXECUTE, self::JOB_READ, self::JOB_WRITE, self::ONBOARDING_USE, self::PROFILE_MANAGE];
}
