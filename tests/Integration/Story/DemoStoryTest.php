<?php

namespace App\Tests\Integration\Story;

use App\Account\Entity\User;
use App\Account\UserRepository;
use App\Chat\ChatHistory;
use App\Garden\Entity\Garden;
use App\Garden\Entity\Planting;
use App\Garden\Entity\Space;
use App\Garden\GardenRepository;
use App\Garden\PlantingRepository;
use App\Garden\SpaceRepository;
use App\Plant\Entity\Plant;
use App\Plant\PlantRepository;
use App\Reminder\Model\ReminderType;
use App\Reminder\NotificationRepository;
use App\Reminder\ReminderChecker;
use App\Story\DemoStory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Uid\UuidV7;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * What "castor demo:reset" then "castor demo:frost" show, on the test database.
 */
final class DemoStoryTest extends KernelTestCase
{
    use ClockSensitiveTrait;

    public function testAliceAndBobShareAGardenCarolHasHersThenAFrostAlertToEachOfThem(): void
    {
        self::bootKernel();
        self::mockTime('2026-12-01 07:00');
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        DemoStory::load();
        $entityManager->clear();

        self::assertSame(
            [
                'admin@hop-garden.test' => ['ROLE_USER', 'ROLE_ADMIN'],
                'alice@hop-garden.test' => ['ROLE_USER'],
                'bob@hop-garden.test' => ['ROLE_USER'],
                'carol@hop-garden.test' => ['ROLE_USER'],
                'dave@hop-garden.test' => [],
            ],
            array_merge(...array_map(static fn (User $user): array => [$user->getEmail() => $user->getRoles()], self::getContainer()->get(UserRepository::class)->findBy([], ['email' => 'ASC']))),
            'The administrator, three members and one on the waiting list, as castor demo:reset prints them.',
        );
        self::assertTrue(self::getContainer()->get(UserRepository::class)->findOneByEmail('dave@hop-garden.test')?->isWaiting());

        $users = self::getContainer()->get(UserRepository::class);
        $gardens = self::getContainer()->get(GardenRepository::class);
        $alice = $users->findOneByEmail('alice@hop-garden.test') ?? self::fail();
        $bob = $users->findOneByEmail('bob@hop-garden.test') ?? self::fail();
        $carol = $users->findOneByEmail('carol@hop-garden.test') ?? self::fail();
        $membersOf = static fn (Garden $garden): array => [$garden->getName() => array_map(static fn ($membership): string => $membership->getUser()->getDisplayName() . ' ' . $membership->getRole()->value, $garden->getMemberships())];
        self::assertSame(
            ['The allotment' => ['Alice owner', 'Bob member'], "Carol's balcony" => ['Carol owner']],
            array_merge(...array_map($membersOf, [...$gardens->findOfMember($alice), ...$gardens->findOfMember($carol)])),
        );
        self::assertSame(['the-allotment', 'the-orchard'], array_map(static fn (Garden $garden): string => $garden->getSlug(), $gardens->findOfMember($bob)), 'Bob goes from one garden to the other.');
        self::assertSame(['Malus domestica'], array_map(static fn (Plant $plant): string => $plant->getScientificName(), self::getContainer()->get(PlantRepository::class)->findValidated($gardens->findOfMember($bob)[1])));
        self::assertSame([], $gardens->findOfMember($users->findOneByEmail('admin@hop-garden.test') ?? self::fail()), 'The wizard makes the first one.');
        $allotment = $gardens->findOfMember($bob)[0];
        $balcony = $gardens->findOfMember($carol)[0];

        self::assertSame(['English lavender'], array_map(static fn (Plant $plant): string => $plant->getCommonName(), self::getContainer()->get(PlantRepository::class)->findValidated($balcony)));
        self::assertSame(['Lavender of the window box'], array_map(static fn (Planting $planting): string => $planting->getName(), self::getContainer()->get(PlantingRepository::class)->findAllByName($balcony)));

        $plants = self::getContainer()->get(PlantRepository::class)->findValidated($allotment);
        self::assertSame(
            ['Climbing rose' => [ReminderType::Pruning, ReminderType::Deadheading], 'Lemon tree' => [ReminderType::Frost]],
            array_merge(...array_map(static fn (Plant $plant): array => [$plant->getCommonName() => $plant->getEnabledReminderTypes()], $plants)),
        );
        $plantings = self::getContainer()->get(PlantingRepository::class)->findAllByName($allotment);
        self::assertSame(
            ['Lemon tree on the terrace' => ['Rose on the trellis'], 'Rose by the gate' => [], 'Rose on the trellis' => ['Lemon tree on the terrace']],
            array_merge(...array_map(static fn (Planting $planting): array => [$planting->getName() => array_map(static fn (Planting $neighbour): string => $neighbour->getName(), $planting->getNeighbours())], $plantings)),
        );
        self::assertSame(
            ['Front garden' => 'Full sun, clay', 'North bed' => 'Shade, clay', 'Terrace' => 'Part shade, loamy'],
            array_merge(...array_map(static fn (Space $space): array => [$space->getName() => $space->describeConditions(self::getContainer()->get(TranslatorInterface::class))], self::getContainer()->get(SpaceRepository::class)->findAllByName($allotment))),
        );

        self::assertSame(1, self::getContainer()->get(ReminderChecker::class)->check($allotment, self::mockTime('2026-12-01 07:00')->now()));
        self::assertSame(0, self::getContainer()->get(ReminderChecker::class)->check($balcony, self::mockTime('2026-12-01 07:00')->now()), 'A lavender is hardy to -15 °C.');
        $entityManager->flush();
        $messagesOf = static fn (User $member, Garden $garden): array => array_map(static fn ($notification): string => $notification->getMessage(), self::getContainer()->get(NotificationRepository::class)->findLatest($member, $garden));
        self::assertSame(['Frost expected on Tuesday 1 December (-3.2 °C): bring Lemon tree indoors. Down to -12.4 °C on Thursday 3 December.'], $messagesOf($alice, $allotment));
        self::assertSame(["Gel annoncé le mardi 1 décembre (-3,2\u{a0}°C)\u{a0}: rentrez Lemon tree à l’abri. Jusqu’à -12,4\u{a0}°C le jeudi 3 décembre."], $messagesOf($bob, $allotment), 'In French, for Bob.');
        self::assertSame([], $messagesOf($carol, $balcony));
    }

    public function testTheConversationsAreGone(): void
    {
        self::bootKernel();
        $conversationId = new UuidV7();
        self::getContainer()->get('ai.message_store.doctrine.dbal.chat')->save(MessageBag::forId($conversationId, Message::ofUser('hortensia')));
        self::assertCount(1, self::getContainer()->get(ChatHistory::class)->entries($conversationId));

        DemoStory::load();

        self::assertSame([], self::getContainer()->get(ChatHistory::class)->entries($conversationId));
    }
}
