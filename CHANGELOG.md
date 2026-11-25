<!-- file generated with AI assistance: Claude Code - 2026-11-25 20:35:59 UTC -->

# Changelog

All notable changes to this bundle. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/); versions follow [SemVer](https://semver.org/) and come from the Git tags.

## [0.1.0] - 2026-11-25

Initial public release.

### Added

- The connection type `firecrawl` of dmstr/api-configuration-bundle (schema branch `schema.json`, health probe, name uniqueness), the Firecrawl v2 client behind `FirecrawlClientInterface` and `FirecrawlClientFactoryInterface`, the connection resolver and the seed command `firecrawl:connection:seed`.
- The connection type `file-converter` with health probe and the seed command `firecrawl:file-converter:seed`.
- Four synchronous pass-through resources under `/api/firecrawl` (scrape, map, crawl, batch scrape) with console twins.
- The job reference table `ref_firecrawl_job` (`Refs\FirecrawlJob`) under `/api/refs/firecrawl_jobs` with start, sync, background scan and background import, and console twins.
- The import of a completed crawl or batch scrape through the port `Import\PageImporterInterface`, which the host application implements.
- Crawl profiles under `/api/admin/crawl_profiles` (discover, crawl and import actions as background jobs, encrypted site login, document files through the `file-converter` connection), the read-only URL list `/api/admin/crawl_url_lists` and the commands `app:crawl:discover`, `app:crawl:run`, `app:crawl:import` and `firecrawl:crawl-profile:import`.
- Four read-only crawl onboarding MCP tools (`crawl_sample_start`, `crawl_sample_analyze`, `crawl_map_site`, `crawl_onboarding_reply`) with boilerplate analysis.
- The permissions `firecrawl:passthru:execute`, `firecrawl:job:read`, `firecrawl:job:write`, `firecrawl:onboarding:use` and `firecrawl:profile:manage`, announced at the container tag `za7_core.permission`; the host application provides the voter.
- The scriptable test seam `Testing\FakeFirecrawlClientFactory`.
- The new baseline migration `Dmstr\Firecrawl\Migrations\Version20261003000000`, which creates `ref_firecrawl_job` in its original form and leaves an existing table unchanged, so installations whose table was created by the host application migrate without changes. The migrations `Version20261003001023`, `Version20261105130000`, `Version20261106120000`, `Version20261106140000` and `Version20261106150000` keep their class names and SQL (PostgreSQL).

### Requirements

- Licence: MIT.
- PHP >= 8.5 with `ext-dom` and `ext-mbstring`, Symfony 7.4, `api-platform/core` >=4.4.8 (^4.4.8), Doctrine ORM 3, Doctrine DBAL 3, PostgreSQL.
- `dmstr/api-configuration-bundle` ^0.7, `dmstr/api-platform-utils-bundle` ^0.6, `dmstr/openapi-json-schema-bundle` ^0.5, `dmstr/symfony-job-queue-bundle` ^0.6.

[0.1.0]: https://github.com/dmstr/firecrawl-bundle/releases/tag/0.1.0
