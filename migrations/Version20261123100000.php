<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Uid\Uuid;

final class Version20261123100000 extends AbstractMigration
{
    /**
     * The tables whose id becomes a UUID v7.
     */
    private const array TABLES = ['app_user', 'garden', 'membership', 'notification', 'password_reset_request', 'plant', 'planting', 'reminder', 'space'];

    /**
     * Their foreign keys, by table and column: the table they reference, the name of their constraint and what a delete
     * does. Their names, nullability and indexes stay: the column changes its type in place.
     */
    private const array FOREIGN_KEYS = [
        'conversation' => [
            'garden_id' => ['garden', 'FK_8A8E26E939F3B087', 'CASCADE'],
            'user_id' => ['app_user', 'FK_8A8E26E9A76ED395', 'CASCADE'],
        ],
        // Its own id is a UUID already (Version20261122231654)
        'invitation' => [
            'garden_id' => ['garden', 'FK_F11D61A239F3B087', 'CASCADE'],
            'invited_by_id' => ['app_user', 'FK_F11D61A2A7B4A7E3', 'CASCADE'],
        ],
        'membership' => [
            'garden_id' => ['garden', 'FK_86FFD28539F3B087', 'CASCADE'],
            'user_id' => ['app_user', 'FK_86FFD285A76ED395', 'CASCADE'],
        ],
        'notification' => [
            'garden_id' => ['garden', 'FK_BF5476CA39F3B087', 'CASCADE'],
            'recipient_id' => ['app_user', 'FK_BF5476CAE92F8F78', 'CASCADE'],
            'reminder_id' => ['reminder', 'FK_BF5476CAD987BE75', 'CASCADE'],
        ],
        'password_reset_request' => [
            'user_id' => ['app_user', 'FK_C5D0A95AA76ED395', 'CASCADE'],
        ],
        'plant' => [
            'garden_id' => ['garden', 'FK_AB030D7239F3B087', 'CASCADE'],
        ],
        'planting' => [
            'garden_id' => ['garden', 'FK_EB3D080D39F3B087', 'CASCADE'],
            'plant_id' => ['plant', 'FK_EB3D080D1D935652', 'SET NULL'],
            'space_id' => ['space', 'FK_EB3D080D23575340', 'SET NULL'],
        ],
        'reminder' => [
            'plant_id' => ['plant', 'FK_40374F401D935652', 'CASCADE'],
        ],
        'space' => [
            'garden_id' => ['garden', 'FK_2972C13A39F3B087', 'CASCADE'],
        ],
    ];

    /**
     * The columns that held a UUID already, as a string.
     */
    private const array UUID_STRINGS = ['conversation' => ['id'], 'planting' => ['conversation_id', 'proposal_id'], 'space' => ['proposal_id']];

    /**
     * Keep in sync with App\Settings\Entity\Settings::ID.
     */
    private const string SETTINGS_ID = '01a2058d-da55-7e7c-a921-eca72d7f9284';

    private const int BATCH_SIZE = 1000;

    public function getDescription(): string
    {
        return 'Every id becomes a UUID v7, generated in PHP in the order of the ids, and the foreign keys follow; the settings get their fixed one. The ids of the care sheets and the spaces in the conversations of the chat (the results and the arguments of the tool calls) follow too.';
    }

    public function up(Schema $schema): void
    {
        // A UUID for each row, in a column of its own until the switch
        $uuids = [];
        foreach (self::TABLES as $table) {
            $uuids[$table] = $this->generateUuids($table);
            $this->addSql(\sprintf('ALTER TABLE %s ADD uuid UUID DEFAULT NULL', $table));
            foreach (array_chunk($uuids[$table], self::BATCH_SIZE, true) as $batch) {
                $values = implode(', ', array_map(static fn (int $id, string $uuid): string => \sprintf("(%d, '%s'::uuid)", $id, $uuid), array_keys($batch), $batch));
                $this->addSql(\sprintf('UPDATE %1$s SET uuid = new.uuid FROM (VALUES %2$s) AS new (id, uuid) WHERE %1$s.id = new.id', $table, $values));
            }
        }

        // Each foreign key gets the UUID of its row, while both ids are still there
        foreach (self::FOREIGN_KEYS as $table => $columns) {
            foreach ($columns as $column => [$referenced]) {
                $this->addSql(\sprintf('ALTER TABLE %s ADD %s_uuid UUID DEFAULT NULL', $table, $column));
                $this->addSql(\sprintf('UPDATE %1$s SET %2$s_uuid = %3$s.uuid FROM %3$s WHERE %1$s.%2$s = %3$s.id', $table, $column, $referenced));
            }
        }

        foreach (self::FOREIGN_KEYS as $table => $columns) {
            foreach ($columns as [, $constraint]) {
                $this->addSql(\sprintf('ALTER TABLE %s DROP CONSTRAINT %s', $table, $constraint));
            }
        }

        // In place: the primary keys, the indexes and the nullability stay
        foreach (self::TABLES as $table) {
            $this->addSql(\sprintf('ALTER TABLE %s ALTER id DROP IDENTITY', $table));
            $this->addSql(\sprintf('ALTER TABLE %s ALTER id TYPE UUID USING uuid', $table));
            $this->addSql(\sprintf('ALTER TABLE %s DROP uuid', $table));
        }
        foreach (self::FOREIGN_KEYS as $table => $columns) {
            foreach (array_keys($columns) as $column) {
                // Its default (NULL::integer) would not cast
                $this->addSql(\sprintf('ALTER TABLE %s ALTER %s DROP DEFAULT', $table, $column));
                $this->addSql(\sprintf('ALTER TABLE %1$s ALTER %2$s TYPE UUID USING %2$s_uuid', $table, $column));
                $this->addSql(\sprintf('ALTER TABLE %s DROP %s_uuid', $table, $column));
            }
        }
        foreach (self::UUID_STRINGS as $table => $columns) {
            foreach ($columns as $column) {
                $this->addSql(\sprintf('ALTER TABLE %s ALTER %s DROP DEFAULT', $table, $column));
                $this->addSql(\sprintf('ALTER TABLE %1$s ALTER %2$s TYPE UUID USING %2$s::uuid', $table, $column));
            }
        }
        $this->addSql(\sprintf("ALTER TABLE settings ALTER id TYPE UUID USING '%s'::uuid", self::SETTINGS_ID));

        foreach (self::FOREIGN_KEYS as $table => $columns) {
            foreach ($columns as $column => [$referenced, $constraint, $onDelete]) {
                $this->addSql(\sprintf('ALTER TABLE %s ADD CONSTRAINT %s FOREIGN KEY (%s) REFERENCES %s (id) ON DELETE %s NOT DEFERRABLE', $table, $constraint, $column, $referenced, $onDelete));
            }
        }

        $this->rewriteConversations($uuids['plant'], $uuids['space']);
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('The ids of before are gone: restore a backup instead.');
    }

    /**
     * @return array<int, string> a UUID v7 by id, in the order of the ids: the oldest row keeps the oldest UUID
     */
    private function generateUuids(string $table): array
    {
        $uuids = [];
        foreach ($this->connection->iterateColumn(\sprintf('SELECT id FROM %s ORDER BY id', $table)) as $id) {
            $uuids[(int) $id] = Uuid::v7()->toRfc4122(); // @phpstan-ignore cast.int
        }

        return $uuids;
    }

    /**
     * The conversations of the chat keep the ids of the tools in their messages: the cards of their history (the draft
     * of propose_plant, the card of propose_planting) and what the agent reads again. A care sheet or a space deleted
     * since gets a UUID of its own, which finds nothing: its card says it is gone, as before.
     *
     * @param array<int, string> $plants
     * @param array<int, string> $spaces
     */
    private function rewriteConversations(array $plants, array $spaces): void
    {
        foreach ($this->connection->iterateAssociative('SELECT id, messages FROM chat_conversation ORDER BY id') as $row) {
            /** @var array{id: string, messages: string} $row */
            $bag = json_decode($row['messages'], true, flags: JSON_THROW_ON_ERROR);
            if (!\is_array($bag) || !\is_array($bag['messages'] ?? null)) {
                continue;
            }

            $changed = false;
            foreach ($bag['messages'] as $i => $message) {
                if (!\is_array($message)) {
                    continue;
                }

                // The arguments of the tool calls of an answer, and of the call a tool result answers
                foreach ($message['parts'] ?? [] as $j => $part) {
                    if (\is_array($part) && \is_string($part['name'] ?? null) && \is_array($part['arguments'] ?? null)) {
                        $bag['messages'][$i]['parts'][$j]['arguments'] = self::mapIds($part['arguments'], self::idsOf($part['name']), $plants, $spaces, $changed);
                    }
                }
                $toolCall = $message['toolCall'] ?? null;
                if (!\is_array($toolCall) || !\is_string($toolCall['name'] ?? null)) {
                    continue;
                }
                if (\is_array($toolCall['arguments'] ?? null)) {
                    $bag['messages'][$i]['toolCall']['arguments'] = self::mapIds($toolCall['arguments'], self::idsOf($toolCall['name']), $plants, $spaces, $changed);
                }

                // The result of the tool, its JSON as the text of the message and of its parts
                if (\is_string($message['content'] ?? null)) {
                    $bag['messages'][$i]['content'] = self::mapJsonIds($message['content'], self::idsOf($toolCall['name']), $plants, $spaces, $changed);
                }
                foreach ($message['parts'] ?? [] as $j => $part) {
                    if (\is_array($part) && \is_string($part['text'] ?? null)) {
                        $bag['messages'][$i]['parts'][$j]['text'] = self::mapJsonIds($part['text'], self::idsOf($toolCall['name']), $plants, $spaces, $changed);
                    }
                }
            }

            if ($changed) {
                $this->addSql('UPDATE chat_conversation SET messages = ? WHERE id = ?', [json_encode($bag, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION), $row['id']]);
            }
        }
    }

    /**
     * What an "id" is in the arguments and the result of a tool: list_spaces lists spaces, the others care sheets.
     *
     * @return 'plant'|'space'
     */
    private static function idsOf(string $agentToolName): string
    {
        return str_ends_with($agentToolName, 'list_spaces') ? 'space' : 'plant';
    }

    /**
     * @param array<int, string> $plants
     * @param array<int, string> $spaces
     */
    private static function mapJsonIds(string $json, string $ids, array &$plants, array &$spaces, bool &$changed): string
    {
        $data = json_decode($json, true);
        if (!\is_array($data)) {
            return $json;
        }

        $changedHere = false;
        $data = self::mapIds($data, $ids, $plants, $spaces, $changedHere);
        if (!$changedHere) {
            return $json;
        }

        $changed = true;

        return json_encode($data, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
    }

    /**
     * @param array<mixed>       $data
     * @param 'plant'|'space'    $ids    what an "id" is
     * @param array<int, string> $plants
     * @param array<int, string> $spaces
     *
     * @return array<mixed>
     */
    private static function mapIds(array $data, string $ids, array &$plants, array &$spaces, bool &$changed): array
    {
        foreach ($data as $key => $value) {
            $table = match ($key) {
                'plant_id' => 'plant',
                'space_id' => 'space',
                'id' => $ids,
                default => null,
            };

            if (null !== $table && \is_int($value)) {
                if ('plant' === $table) {
                    $data[$key] = $plants[$value] ??= Uuid::v7()->toRfc4122();
                } else {
                    $data[$key] = $spaces[$value] ??= Uuid::v7()->toRfc4122();
                }
                $changed = true;
            } elseif (\is_array($value)) {
                $data[$key] = self::mapIds($value, $ids, $plants, $spaces, $changed);
            }
        }

        return $data;
    }
}
