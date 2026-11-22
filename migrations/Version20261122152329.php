<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261122152329 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The card a planting or a space was added from, in the chat: its key, unique, so that a second tap adds nothing. Null for the existing rows.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE planting ADD proposal_id VARCHAR(255) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_EB3D080DF4792058 ON planting (proposal_id)');
        $this->addSql('ALTER TABLE space ADD proposal_id VARCHAR(255) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_2972C13AF4792058 ON space (proposal_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_EB3D080DF4792058');
        $this->addSql('ALTER TABLE planting DROP proposal_id');
        $this->addSql('DROP INDEX UNIQ_2972C13AF4792058');
        $this->addSql('ALTER TABLE space DROP proposal_id');
    }
}
