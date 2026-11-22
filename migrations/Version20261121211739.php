<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261121211739 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'One list, the garden: the exposure of a planting may be unknown, and every validated plant without a planting gets one.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE planting ALTER exposure DROP NOT NULL');
        // As App\Garden\Model\Planting::forValidatedPlant(): its names and species, nothing of the garden yet
        $this->addSql(<<<'SQL'
            INSERT INTO planting (care_records, name, exposure, soil, diseases, created_at, trefle_id, scientific_name, photo_filename, plant_id, conversation_id, drafting_failed)
            SELECT '[]', plant.common_name, NULL, NULL, NULL, COALESCE(plant.validated_at, plant.created_at), plant.trefle_id, plant.scientific_name, NULL, plant.id, NULL, false
            FROM plant
            WHERE plant.status = 'validated'
                AND NOT EXISTS (SELECT 1 FROM planting WHERE planting.plant_id = plant.id)
            ORDER BY plant.id
            SQL);
    }

    public function down(Schema $schema): void
    {
        // A planting without exposure did not exist before: the validated plants of the chat had none
        $this->addSql('DELETE FROM planting WHERE exposure IS NULL');
        $this->addSql('ALTER TABLE planting ALTER exposure SET NOT NULL');
    }
}
