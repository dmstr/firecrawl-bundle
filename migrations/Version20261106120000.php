<?php

// file generated with AI assistance: Claude Code - 2026-11-06 12:00:00 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The connection type `converter` is renamed to `file-converter`: the stored
 * connections get the new type and flag (`converter-local` becomes
 * `file-converter`), and the crawl profile column follows as
 * `file_converter_connection_id`.
 */
final class Version20261106120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rename the converter connection type to file-converter';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE firecrawl_crawl_profile DROP CONSTRAINT FK_FIRECRAWL_CRAWL_PROFILE_CONV');
        $this->addSql('DROP INDEX IDX_CC7BD4B276D93F54');
        $this->addSql('ALTER TABLE firecrawl_crawl_profile RENAME COLUMN converter_connection_id TO file_converter_connection_id');
        $this->addSql('CREATE INDEX IDX_CC7BD4B2B3145B42 ON firecrawl_crawl_profile (file_converter_connection_id)');
        $this->addSql('ALTER TABLE firecrawl_crawl_profile ADD CONSTRAINT FK_CC7BD4B2B3145B42 FOREIGN KEY (file_converter_connection_id) REFERENCES dmstr_api_configuration (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql(<<<'SQL'
            UPDATE dmstr_api_configuration
            SET type = 'file-converter',
                name = CASE name WHEN 'converter-local' THEN 'file-converter' ELSE name END,
                config_json = ((config_json::jsonb - 'converter') || '{"file-converter": true, "type": "file-converter"}'::jsonb)::json
            WHERE type = 'converter'
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE dmstr_api_configuration
            SET type = 'converter',
                name = CASE name WHEN 'file-converter' THEN 'converter-local' ELSE name END,
                config_json = ((config_json::jsonb - 'file-converter') || '{"converter": true, "type": "converter"}'::jsonb)::json
            WHERE type = 'file-converter'
            SQL);
        $this->addSql('ALTER TABLE firecrawl_crawl_profile DROP CONSTRAINT FK_CC7BD4B2B3145B42');
        $this->addSql('DROP INDEX IDX_CC7BD4B2B3145B42');
        $this->addSql('ALTER TABLE firecrawl_crawl_profile RENAME COLUMN file_converter_connection_id TO converter_connection_id');
        $this->addSql('CREATE INDEX IDX_CC7BD4B276D93F54 ON firecrawl_crawl_profile (converter_connection_id)');
        $this->addSql('ALTER TABLE firecrawl_crawl_profile ADD CONSTRAINT FK_FIRECRAWL_CRAWL_PROFILE_CONV FOREIGN KEY (converter_connection_id) REFERENCES dmstr_api_configuration (id) ON DELETE SET NULL NOT DEFERRABLE');
    }
}
