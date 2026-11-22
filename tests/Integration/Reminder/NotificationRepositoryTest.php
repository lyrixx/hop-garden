<?php

namespace App\Tests\Integration\Reminder;

use App\Account\Entity\User;
use App\Factory\GardenFactory;
use App\Factory\NotificationFactory;
use App\Factory\PlantFactory;
use App\Factory\ReminderFactory;
use App\Factory\UserFactory;
use App\Garden\Entity\Garden;
use App\Reminder\Entity\Notification;
use App\Reminder\Entity\Reminder;
use App\Reminder\NotificationRepository;
use App\Story\DefaultGardenStory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class NotificationRepositoryTest extends KernelTestCase
{
    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function testTheLatestNotificationsFirst(): void
    {
        $reminder = ReminderFactory::createOne();
        self::notify($reminder, 'Older', '2026-03-01 07:00');
        self::notify($reminder, 'Same time, stored first', '2026-04-01 07:00');
        self::notify($reminder, 'Same time, stored last', '2026-04-01 07:00');
        self::notify($reminder, 'Newest', '2026-05-01 07:00');

        $repository = self::getContainer()->get(NotificationRepository::class);
        $owner = self::garden()->getOwner();

        self::assertSame(['Newest', 'Same time, stored last', 'Same time, stored first', 'Older'], self::messages($repository->findLatest($owner, self::garden())));
        self::assertSame(['Newest', 'Same time, stored last'], self::messages($repository->findLatest($owner, self::garden(), 2)));
    }

    public function testEachMemberTheirOwnInTheirGarden(): void
    {
        $bob = UserFactory::new()->approved()->create();
        $shared = GardenFactory::new()->withMembers($bob)->create();
        $reminder = ReminderFactory::createOne(['plant' => PlantFactory::new()->validated()->with(['garden' => $shared])]);
        NotificationFactory::createOne(['reminder' => $reminder, 'message' => 'To the owner']);
        NotificationFactory::createOne(['reminder' => $reminder, 'recipient' => $bob, 'message' => 'To Bob']);
        self::garden()->join($bob, new \DateTimeImmutable('2026-10-02'));
        self::notify(ReminderFactory::createOne(), 'To Bob, of another garden', '2026-04-01 07:00', $bob);

        $repository = self::getContainer()->get(NotificationRepository::class);

        self::assertSame(['To Bob'], self::messages($repository->findLatest($bob, $shared)));
        self::assertSame(['To the owner'], self::messages($repository->findLatest($shared->getOwner(), $shared)));
        self::assertSame(1, $repository->countUnread($bob, $shared));
    }

    public function testTheUnreadOnes(): void
    {
        $reminder = ReminderFactory::createOne();
        $read = self::notify($reminder, 'Read', '2026-03-01 07:00');
        self::notify($reminder, 'Unread', '2026-04-01 07:00');
        $read->markRead(new \DateTimeImmutable('2026-03-01 08:00'));
        $read->markRead(new \DateTimeImmutable('2026-03-02 08:00'));
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        $repository = self::getContainer()->get(NotificationRepository::class);

        self::assertSame(1, $repository->countUnread(self::garden()->getOwner(), self::garden()));
        self::assertSame(['Unread'], self::messages($repository->findUnread(self::garden()->getOwner(), self::garden())));
        self::assertSame('2026-03-01 08:00', $read->getReadAt()?->format('Y-m-d H:i'), 'Read the first time.');
    }

    private static function notify(Reminder $reminder, string $message, string $createdAt, ?User $recipient = null): Notification
    {
        return NotificationFactory::createOne(['reminder' => $reminder, 'message' => $message, 'createdAt' => new \DateTimeImmutable($createdAt)] + (null !== $recipient ? ['recipient' => $recipient] : []));
    }

    /**
     * @param list<Notification> $notifications
     *
     * @return list<string>
     */
    private static function messages(array $notifications): array
    {
        return array_map(static fn (Notification $notification): string => $notification->getMessage(), $notifications);
    }

    private static function garden(): Garden
    {
        $garden = DefaultGardenStory::get('garden');
        self::assertInstanceOf(Garden::class, $garden);

        return $garden;
    }
}
