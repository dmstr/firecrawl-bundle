<?php

// file generated with AI assistance: Claude Code - 2026-11-06 15:00:00 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A crawl profile no longer names its file converter: the crawl uses the
 * only active `file-converter` connection. down() brings the column back
 * empty; the former links are not restored.
 */
final class Version20261106150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Crawl profiles without file_converter_connection_id';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE firecrawl_crawl_profile DROP CONSTRAINT FK_CC7BD4B2B3145B42');
        $this->addSql('DROP INDEX IDX_CC7BD4B2B3145B42');
        $this->addSql('ALTER TABLE firecrawl_crawl_profile DROP file_converter_connection_id');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE firecrawl_crawl_profile ADD file_converter_connection_id UUID DEFAULT NULL');
        $this->addSql("COMMENT ON COLUMN firecrawl_crawl_profile.file_converter_connection_id IS '(DC2Type:uuid)'");
        $this->addSql('CREATE INDEX IDX_CC7BD4B2B3145B42 ON firecrawl_crawl_profile (file_converter_connection_id)');
        $this->addSql('ALTER TABLE firecrawl_crawl_profile ADD CONSTRAINT FK_CC7BD4B2B3145B42 FOREIGN KEY (file_converter_connection_id) REFERENCES dmstr_api_configuration (id) ON DELETE SET NULL NOT DEFERRABLE');
    }
}
