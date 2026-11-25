<?php

// file generated with AI assistance: Claude Code - 2026-11-06 14:00:00 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The `import` section of a crawl profile: target collection and chunk
 * sizes of the Import action; null until it is set up.
 */
final class Version20261106140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Import section of crawl profiles (firecrawl_crawl_profile.import)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE firecrawl_crawl_profile ADD import JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE firecrawl_crawl_profile DROP import');
    }
}
