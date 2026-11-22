<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\String\Slugger\AsciiSlugger;

final class Version20261123110000 extends AbstractMigration
{
    /**
     * By table: the column the slug comes from, the slug of a name without a letter, and the index of its uniqueness. A
     * garden is unique in the whole site, the rest in its garden (the rows of before the gardens, of none yet, among
     * themselves: they will join one new garden).
     */
    private const array TABLES = [
        'garden' => ['name', 'garden', 'UNIQ_3C0918EA989D9B62'],
        'plant' => ['scientific_name', 'species', 'UNIQ_AB030D7239F3B087989D9B62'],
        'planting' => ['name', 'plant', 'UNIQ_EB3D080D39F3B087989D9B62'],
        'space' => ['name', 'space', 'UNIQ_2972C13A39F3B087989D9B62'],
    ];

    /**
     * As App\Garden\Slugs: the pages under a resource, and the length of a slug.
     */
    private const array RESERVED = ['new'];
    private const int MAX_LENGTH = 100;

    private const int BATCH_SIZE = 1000;

    public function getDescription(): string
    {
        return 'The slugs of the URLs: of each garden from its name, unique in the whole site; of each species from its Latin name, plant and space from its name, unique in its garden. The oldest row keeps the bare slug, the next ones get a suffix ("rose-2").';
    }

    public function up(Schema $schema): void
    {
        $slugger = new AsciiSlugger('en');

        foreach (self::TABLES as $table => [$source, $fallback, $index]) {
            $this->addSql(\sprintf('ALTER TABLE %s ADD slug VARCHAR(255) DEFAULT NULL', $table));

            // UUID v7: the oldest first
            $query = 'garden' === $table
                ? \sprintf('SELECT id, %s AS source, NULL AS scope FROM garden ORDER BY id', $source)
                : \sprintf('SELECT id, %s AS source, garden_id AS scope FROM %s ORDER BY id', $source, $table);

            $slugs = [];
            $taken = [];
            foreach ($this->connection->iterateAssociative($query) as $row) {
                /** @var array{id: string, source: string, scope: ?string} $row */
                $base = rtrim(substr($slugger->slug($row['source'])->lower()->toString(), 0, self::MAX_LENGTH), '-');
                $base = '' !== $base ? $base : $fallback;
                $scope = $row['scope'] ?? '';
                $taken[$scope] ??= array_flip(self::RESERVED);

                $slug = $base;
                for ($suffix = 2; isset($taken[$scope][$slug]); ++$suffix) {
                    $slug = $base . '-' . $suffix;
                }
                $taken[$scope][$slug] = true;
                $slugs[$row['id']] = $slug;
            }

            foreach (array_chunk($slugs, self::BATCH_SIZE, true) as $batch) {
                $values = implode(', ', array_map(fn (string $id, string $slug): string => \sprintf('(%s::uuid, %s)', $this->connection->quote($id), $this->connection->quote($slug)), array_keys($batch), $batch));
                $this->addSql(\sprintf('UPDATE %1$s SET slug = new.slug FROM (VALUES %2$s) AS new (id, slug) WHERE %1$s.id = new.id', $table, $values));
            }

            $this->addSql(\sprintf('ALTER TABLE %s ALTER slug DROP DEFAULT', $table));
            $this->addSql(\sprintf('ALTER TABLE %s ALTER slug SET NOT NULL', $table));
            $this->addSql('garden' === $table
                ? \sprintf('CREATE UNIQUE INDEX %s ON garden (slug)', $index)
                : \sprintf('CREATE UNIQUE INDEX %s ON %s (garden_id, slug)', $index, $table));
        }
    }

    public function down(Schema $schema): void
    {
        foreach (self::TABLES as $table => [, , $index]) {
            $this->addSql(\sprintf('DROP INDEX %s', $index));
            $this->addSql(\sprintf('ALTER TABLE %s DROP slug', $table));
        }
    }
}
