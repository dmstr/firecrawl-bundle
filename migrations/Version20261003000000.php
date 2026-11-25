<?php

// file generated with AI assistance: Claude Code - 2026-11-25 20:35:59 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Baseline of dmstr/firecrawl-bundle: creates the job reference table
 * `ref_firecrawl_job` of {@see \Dmstr\Firecrawl\Entity\Refs\FirecrawlJob} in
 * its original form, without the connection link `api_configuration_id`,
 * which {@see Version20261003001023} adds.
 *
 * Idempotent: earlier installations had the table created by the host
 * application. The whole creation (table, index, column comments) runs in
 * one PL/pgSQL block that does nothing when `ref_firecrawl_job` already
 * resolves on the search path, so an existing table keeps its structure
 * and rows. The migration is not skipped: it executes and is recorded as
 * executed either way, and Version20261003001023 then finds the table.
 */
final class Version20261003000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create ref_firecrawl_job unless it exists (baseline of dmstr/firecrawl-bundle)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            DO $$
            BEGIN
                IF to_regclass('ref_firecrawl_job') IS NULL THEN
                    CREATE TABLE ref_firecrawl_job (id UUID NOT NULL, type VARCHAR(20) NOT NULL, url TEXT NOT NULL, options JSON DEFAULT NULL, firecrawl_id VARCHAR(64) DEFAULT NULL, status VARCHAR(20) NOT NULL, result_summary JSON DEFAULT NULL, error_message TEXT DEFAULT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY(id));
                    CREATE INDEX idx_firecrawl_job_status ON ref_firecrawl_job (status);
                    COMMENT ON COLUMN ref_firecrawl_job.id IS '(DC2Type:uuid)';
                    COMMENT ON COLUMN ref_firecrawl_job.created_at IS '(DC2Type:datetimetz_immutable)';
                    COMMENT ON COLUMN ref_firecrawl_job.updated_at IS '(DC2Type:datetimetz_immutable)';
                END IF;
            END
            $$
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE ref_firecrawl_job');
    }
}
