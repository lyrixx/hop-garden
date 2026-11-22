<?php

namespace App\Tests\Integration\Garden;

use App\Chat\ConversationRepository;
use App\Factory\GardenFactory;
use App\Factory\NotificationFactory;
use App\Factory\PlantFactory;
use App\Factory\PlantingFactory;
use App\Factory\SpaceFactory;
use App\Factory\UserFactory;
use App\Garden\GardenRepository;
use App\Reminder\Model\ReminderType;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Uid\UuidV7;

/**
 * The data of before the gardens, between the migration that adds them and the one that makes them required: the test
 * puts the schema back between both (a transaction of PostgreSQL rolls it back), with rows of before.
 */
final class AdoptOrphansCommandTest extends KernelTestCase
{
    private const array COLUMNS = ['space' => ['garden_id'], 'plant' => ['garden_id'], 'planting' => ['garden_id'], 'notification' => ['garden_id', 'recipient_id']];

    public function testTheRowsOfBeforeJoinANewGardenOfTheMember(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        $orphanConversation = $this->rowsOfBefore($connection);
        $alice = UserFactory::new()->approved()->create(['email' => 'alice@example.com']);

        $tester = $this->command();
        $tester->execute(['--email' => 'alice@example.com', '--name' => 'My garden', '--city' => 'Lyon']);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('at Lyon, Auvergne-Rhône-Alpes, France (Europe/Paris), has every row of before', (string) preg_replace('/\s+/', ' ', $tester->getDisplay()));
        $gardens = self::getContainer()->get(GardenRepository::class)->findOfMember($alice);
        self::assertCount(1, $gardens);
        $garden = $gardens[0];
        self::assertSame('My garden', $garden->getName());
        self::assertSame($alice, $garden->getOwner());

        foreach (self::COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                self::assertSame(0, (int) $connection->fetchOne(\sprintf('SELECT COUNT(*) FROM %s WHERE %s IS NULL', $table, $column)), $table . '.' . $column);
            }
        }
        self::assertSame(['space' => 1, 'plant' => 1, 'planting' => 1, 'notification' => 1], array_map(intval(...), array_combine(array_keys(self::COLUMNS), array_map(static fn (string $table): mixed => $connection->fetchOne(\sprintf('SELECT COUNT(*) FROM %s WHERE garden_id = ?', $table), [$garden->getId()]), array_keys(self::COLUMNS)))));
        self::assertSame($alice->getId(), (int) $connection->fetchOne('SELECT recipient_id FROM notification'));
        self::assertTrue(self::getContainer()->get(ConversationRepository::class)->findOneById($orphanConversation)?->isOf($alice, $garden), 'Her conversation of before.');

        $tester->execute(['--email' => 'alice@example.com', '--name' => 'Again', '--city' => 'Lyon']);
        self::assertCount(1, self::getContainer()->get(GardenRepository::class)->findOfMember($alice), 'Nothing left to adopt: no second garden.');
    }

    public function testAfterTheMigrationThereIsNothingToAdopt(): void
    {
        self::bootKernel();
        UserFactory::new()->approved()->create(['email' => 'alice@example.com']);

        $tester = $this->command();
        $tester->execute(['--email' => 'alice@example.com', '--city' => 'Lyon']);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Nothing to adopt: every row has its garden already.', (string) preg_replace('/\s+/', ' ', $tester->getDisplay()));
    }

    public function testAnApprovedMemberAndACityAreNeeded(): void
    {
        self::bootKernel();
        $this->rowsOfBefore(self::getContainer()->get(Connection::class));
        UserFactory::new()->verified()->create(['email' => 'dave@example.com']);
        UserFactory::new()->approved()->create(['email' => 'alice@example.com']);
        $tester = $this->command();

        $tester->execute(['--email' => 'dave@example.com', '--city' => 'Lyon']);
        self::assertSame(2, $tester->getStatusCode());
        self::assertStringContainsString('No approved member with the email "dave@example.com"', (string) preg_replace('/\s+/', ' ', $tester->getDisplay()));

        $tester->execute(['--email' => 'alice@example.com', '--city' => 'Atlantis']);
        self::assertSame(2, $tester->getStatusCode());
        self::assertStringContainsString('No place found for the city "Atlantis"', (string) preg_replace('/\s+/', ' ', $tester->getDisplay()));
    }

    /**
     * The schema between the two migrations, and a row of each kind without garden: a space, a care sheet with its
     * reminder, a plant, a notification, a conversation of the chat.
     */
    private function rowsOfBefore(Connection $connection): UuidV7
    {
        foreach (self::COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                $connection->executeStatement(\sprintf('ALTER TABLE %s ALTER %s DROP NOT NULL', $table, $column));
            }
        }

        $before = GardenFactory::createOne();
        $rose = PlantFactory::new()->validated()->withEnabledReminders(ReminderType::Pruning)->create(['garden' => $before]);
        PlantingFactory::new()->inSpace(SpaceFactory::new(['garden' => $before]))->linkedTo($rose)->create();
        NotificationFactory::createOne(['reminder' => $rose->getReminders()[0]]);
        $conversation = new UuidV7();
        self::getContainer()->get('ai.message_store.doctrine.dbal.chat')->save(MessageBag::forId($conversation, Message::ofUser('hortensia')));

        foreach (self::COLUMNS as $table => $columns) {
            $connection->executeStatement(\sprintf('UPDATE %s SET %s', $table, implode(', ', array_map(static fn (string $column): string => $column . ' = NULL', $columns))));
        }
        $connection->executeStatement('DELETE FROM garden WHERE id = ?', [$before->getId()]);
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        return $conversation;
    }

    private function command(): CommandTester
    {
        return new CommandTester((new Application(self::$kernel ?? throw new \LogicException()))->find('app:garden:adopt-orphans'));
    }
}
