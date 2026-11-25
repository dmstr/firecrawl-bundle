<?php

// file generated with AI assistance: Claude Code - 2026-10-03 00:10:23 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Job reference table of dmstr/firecrawl-bundle: every row of
 * `ref_firecrawl_job` belongs to the `firecrawl` connection it runs on
 * (`api_configuration_id`, FK ON DELETE CASCADE onto the platform table
 * dmstr_api_configuration), and a remote id is unique per connection
 * (partial unique index, only rows with a remote id).
 *
 * The table itself is created by the bundle's baseline migration
 * {@see Version20261003000000}, which runs first; this migration only adds
 * the connection link.
 *
 * `down()` removes the link again and keeps the table and its rows.
 */
final class Version20261003001023 extends AbstractMigration
{
    private const string FK = 'FK_DA018B08316D888C';

    public function getDescription(): string
    {
        return 'Link ref_firecrawl_job to its firecrawl connection (api_configuration_id, partial unique index on the remote id); rows without a connection are deleted';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$schema->hasTable('ref_firecrawl_job'), 'ref_firecrawl_job must exist (created by Version20261003000000) before this migration runs.');

        // Existing job references have no connection to point to, so they
        // are deleted instead of being migrated to a guessed connection.
        $this->addSql('DELETE FROM ref_firecrawl_job');
        $this->addSql('ALTER TABLE ref_firecrawl_job ADD api_configuration_id UUID NOT NULL');
        $this->addSql("COMMENT ON COLUMN ref_firecrawl_job.api_configuration_id IS '(DC2Type:uuid)'");
        $this->addSql('CREATE UNIQUE INDEX uniq_ref_firecrawl_job_api_configuration_firecrawl_id ON ref_firecrawl_job (api_configuration_id, firecrawl_id) WHERE (firecrawl_id IS NOT NULL)');
        $this->addSql('CREATE INDEX idx_ref_firecrawl_job_api_configuration ON ref_firecrawl_job (api_configuration_id)');
        $this->addSql('ALTER TABLE ref_firecrawl_job ADD CONSTRAINT '.self::FK.' FOREIGN KEY (api_configuration_id) REFERENCES dmstr_api_configuration (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ref_firecrawl_job DROP CONSTRAINT '.self::FK);
        $this->addSql('DROP INDEX idx_ref_firecrawl_job_api_configuration');
        $this->addSql('DROP INDEX uniq_ref_firecrawl_job_api_configuration_firecrawl_id');
        $this->addSql('ALTER TABLE ref_firecrawl_job DROP api_configuration_id');
    }
}
