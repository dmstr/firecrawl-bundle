<!-- file generated with AI assistance: Claude Code - 2026-11-25 20:35:59 UTC -->

# CLAUDE.md

This is a public open-source package maintained under github.com/dmstr.

Symfony bundle for self-hosted Firecrawl v2: the connection types `firecrawl` and `file-converter` of dmstr/api-configuration-bundle, a REST client behind an interface with a scriptable test fake, synchronous pass-through resources under `/api/firecrawl`, the job reference table `ref_firecrawl_job` under `/api/refs/firecrawl_jobs` with background scan and import (through the port `Import\PageImporterInterface` the host application implements), crawl profiles under `/api/admin/crawl_profiles`, crawl onboarding MCP tools and seed commands. See `README.md` for the concepts.

## Language policy

**Everything in this repository is in English**: commit messages, code, comments, documentation, test names and test data, and all communication about the repository (pull request titles and descriptions, code review, issues, and chat with the coding assistant). These packages are public and English-only; do not reference private projects, internal trackers or company internals.

## Requirements and tests

PHP >= 8.5 (see `composer.json`). The PHPUnit suite (configuration `phpunit.dist.xml`) has two test suites:

- `unit` (`tests/Unit`): no kernel, no database. Run it with `vendor/bin/phpunit --testsuite unit`.
- `integration` (`tests/Integration`): boots the test kernel `tests/Kernel/TestKernel.php` (the dependency bundles, `FakeFirecrawlClientFactory` as client factory, an in-memory `PageImporterInterface`, header-based test authentication, a test permission voter; fixtures in `tests/Fixtures`). Tests in the group `database` need a PostgreSQL server from `DATABASE_URL`; the schema is created by running the migrations, the bundle's own ones included. Without a server, run `vendor/bin/phpunit --testsuite integration --exclude-group database`.

No test contacts a Firecrawl instance (`Dmstr\Firecrawl\Testing\FakeFirecrawlClientFactory`), except the smoke test in the group `live` (`tests/Unit/Live/FirecrawlLiveTest.php`), which is skipped unless `FIRECRAWL_LIVE_URL` and `FIRECRAWL_LIVE_API_KEY` are set and never runs in CI. CI is the GitHub Action `.github/workflows/tests.yml` (PHP 8.5, PostgreSQL service container), which runs both suites. Test helpers live only under `tests/` and `src/Testing/`.

## Invariants

- The migration classes `Dmstr\Firecrawl\Migrations\Version20261003000000`, `Version20261003001023`, `Version20261105130000`, `Version20261106120000`, `Version20261106140000` and `Version20261106150000` and the migrations path `__DIR__.'/../migrations'` in `FirecrawlBundle` never change: applications store the class names. The baseline `Version20261003000000` stays idempotent (no `skipIf`, no change to an existing `ref_firecrawl_job`), because older installations created the table themselves.
- Migrations of this bundle sort by class name after `Dmstr\ApiConfiguration\Migrations` (the only foreign table referenced is `dmstr_api_configuration`); a new migration keeps a timestamp later than the existing ones.
- Console command names, routes, operation names, table names and the namespace `Dmstr\Firecrawl` are public API, including the command names with the prefix `app:crawl:`.
- The bundle ships no implementation of `Dmstr\Firecrawl\Import\PageImporterInterface`; the host application aliases it.
- The bundle checks only permissions with `is_granted()`: the constants of `Dmstr\Firecrawl\Security\FirecrawlPermissions`, tagged `za7_core.permission` in `config/services.yaml`. No `ROLE_*` name is checked in `src/` or `config/`; the host application maps permissions to roles through its own voter. The tag `za7_core.permission` and the parameters `za7_core.permissions` and `za7_core.permissions.registered` keep their names. A new permission gets a constant, a tag, a test and a README update in the same change.
- Services that log use the binding `$firecrawlLogger` (Monolog channel `firecrawl`, prepended by the bundle).
