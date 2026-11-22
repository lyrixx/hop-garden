<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261121195115 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The care sheet of a planting drafted by the agent: its conversation, and whether the attempt failed.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE planting ADD conversation_id VARCHAR(255) DEFAULT NULL');
        // The plantings already there never failed a draft
        $this->addSql('ALTER TABLE planting ADD drafting_failed BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE planting ALTER drafting_failed DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE planting DROP conversation_id');
        $this->addSql('ALTER TABLE planting DROP drafting_failed');
    }
}
