<?php

namespace App\Tests\Integration\Reminder;

use App\Factory\NotificationFactory;
use App\Factory\ReminderFactory;
use App\Reminder\Entity\Notification;
use App\Reminder\Entity\Reminder;
use App\Reminder\NotificationRepository;
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

        self::assertSame(['Newest', 'Same time, stored last', 'Same time, stored first', 'Older'], self::messages($repository->findLatest()));
        self::assertSame(['Newest', 'Same time, stored last'], self::messages($repository->findLatest(2)));
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

        self::assertSame(1, $repository->countUnread());
        self::assertSame(['Unread'], self::messages($repository->findUnread()));
        self::assertSame('2026-03-01 08:00', $read->getReadAt()?->format('Y-m-d H:i'), 'Read the first time.');
    }

    private static function notify(Reminder $reminder, string $message, string $createdAt): Notification
    {
        return NotificationFactory::createOne(['reminder' => $reminder, 'message' => $message, 'createdAt' => new \DateTimeImmutable($createdAt)]);
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
}
