<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261122171755 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The settings of the application, in one row created by the first choice: the preferred locale, null until chosen.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE settings (id INT NOT NULL, preferred_locale VARCHAR(255) DEFAULT NULL, PRIMARY KEY (id))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE settings');
    }
}
