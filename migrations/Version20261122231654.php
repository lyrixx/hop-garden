<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261122231654 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The invitations to a garden, their token hashed, one open per address; a notification tells a new member too.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE invitation (id UUID NOT NULL, email VARCHAR(255) NOT NULL, token_hash VARCHAR(255) NOT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, accepted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, revoked_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, garden_id INT NOT NULL, invited_by_id INT NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_F11D61A2B3BC57DA ON invitation (token_hash)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_F11D61A239F3B087E7927C74 ON invitation (garden_id, email) WHERE ((accepted_at IS NULL) AND (revoked_at IS NULL))');
        $this->addSql('CREATE INDEX IDX_F11D61A239F3B087 ON invitation (garden_id)');
        $this->addSql('CREATE INDEX IDX_F11D61A2A7B4A7E3 ON invitation (invited_by_id)');
        $this->addSql('ALTER TABLE invitation ADD CONSTRAINT FK_F11D61A239F3B087 FOREIGN KEY (garden_id) REFERENCES garden (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE invitation ADD CONSTRAINT FK_F11D61A2A7B4A7E3 FOREIGN KEY (invited_by_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');

        $this->addSql('ALTER TABLE notification ADD invitation_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE notification ALTER reminder_id DROP NOT NULL');
        $this->addSql('ALTER TABLE notification ADD CONSTRAINT FK_BF5476CAA35D7AF0 FOREIGN KEY (invitation_id) REFERENCES invitation (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_BF5476CAA35D7AF0 ON notification (invitation_id)');
    }

    public function down(Schema $schema): void
    {
        // The notifications of the new members have no reminder
        $this->addSql('DELETE FROM notification WHERE invitation_id IS NOT NULL');
        $this->addSql('ALTER TABLE notification DROP CONSTRAINT FK_BF5476CAA35D7AF0');
        $this->addSql('DROP INDEX IDX_BF5476CAA35D7AF0');
        $this->addSql('ALTER TABLE notification DROP invitation_id');
        $this->addSql('ALTER TABLE notification ALTER reminder_id SET NOT NULL');

        $this->addSql('DROP TABLE invitation');
    }
}
