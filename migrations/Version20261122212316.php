<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Its work moved to Version20261123120000, after the UUIDs and the slugs: a database of before the gardens gets its
 * account and adopts its rows with the entities as they are now ("castor app:garden:adopt-orphans"). A database past it
 * already is unchanged.
 */
final class Version20261122212316 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Nothing: the garden of every row is required later, by Version20261123120000.';
    }

    public function up(Schema $schema): void
    {
    }

    public function down(Schema $schema): void
    {
    }
}
