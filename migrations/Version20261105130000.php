<?php

// file generated with AI assistance: Claude Code - 2026-11-05 13:00:00 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Crawl profiles: one row per website the crawl walks, linked to its
 * `firecrawl` connection (required) and its `converter` connection (optional,
 * cleared when the connection is deleted). The former YAML profiles are
 * brought in with `firecrawl:crawl-profile:import`.
 */
final class Version20261105130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Crawl profiles (firecrawl_crawl_profile)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE firecrawl_crawl_profile (id UUID NOT NULL, firecrawl_connection_id UUID NOT NULL, converter_connection_id UUID DEFAULT NULL, name VARCHAR(100) NOT NULL, active BOOLEAN NOT NULL, discover JSON NOT NULL, crawl JSON NOT NULL, auth JSON DEFAULT NULL, password_encrypted TEXT DEFAULT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_firecrawl_crawl_profile_name ON firecrawl_crawl_profile (name)');
        $this->addSql('CREATE INDEX IDX_CC7BD4B21A323578 ON firecrawl_crawl_profile (firecrawl_connection_id)');
        $this->addSql('CREATE INDEX IDX_CC7BD4B276D93F54 ON firecrawl_crawl_profile (converter_connection_id)');
        foreach (['id' => 'uuid', 'firecrawl_connection_id' => 'uuid', 'converter_connection_id' => 'uuid', 'created_at' => 'datetimetz_immutable', 'updated_at' => 'datetimetz_immutable'] as $column => $type) {
            $this->addSql(\sprintf("COMMENT ON COLUMN firecrawl_crawl_profile.%s IS '(DC2Type:%s)'", $column, $type));
        }
        $this->addSql('ALTER TABLE firecrawl_crawl_profile ADD CONSTRAINT FK_FIRECRAWL_CRAWL_PROFILE_FC FOREIGN KEY (firecrawl_connection_id) REFERENCES dmstr_api_configuration (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE firecrawl_crawl_profile ADD CONSTRAINT FK_FIRECRAWL_CRAWL_PROFILE_CONV FOREIGN KEY (converter_connection_id) REFERENCES dmstr_api_configuration (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE firecrawl_crawl_profile');
    }
}
