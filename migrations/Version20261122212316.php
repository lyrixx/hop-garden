<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261122212316 extends AbstractMigration
{
    private const array TABLES = ['notification' => ['garden_id', 'recipient_id'], 'plant' => ['garden_id'], 'planting' => ['garden_id'], 'space' => ['garden_id']];

    public function getDescription(): string
    {
        return 'Every space, care sheet, plant and notification belongs to a garden, and every notification to a member of it.';
    }

    public function up(Schema $schema): void
    {
        foreach (self::TABLES as $table => $columns) {
            foreach ($columns as $column) {
                $orphans = (int) $this->connection->fetchOne(\sprintf('SELECT COUNT(*) FROM %s WHERE %s IS NULL', $table, $column));
                $this->abortIf(0 < $orphans, \sprintf('%d rows of "%s" have no %s: they are of before the gardens. Give them one first: "castor app:garden:adopt-orphans --email=you@example.com --name=\'My garden\' --city=Paris" (bin/console app:garden:adopt-orphans in production, see the README).', $orphans, $table, $column));
                $this->addSql(\sprintf('ALTER TABLE %s ALTER %s SET NOT NULL', $table, $column));
            }
        }
    }

    public function down(Schema $schema): void
    {
        foreach (self::TABLES as $table => $columns) {
            foreach ($columns as $column) {
                $this->addSql(\sprintf('ALTER TABLE %s ALTER %s DROP NOT NULL', $table, $column));
            }
        }
    }
}
