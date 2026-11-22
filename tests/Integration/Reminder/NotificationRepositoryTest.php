<?php

namespace App\Tests\Integration\Reminder;

use App\Reminder\Model\Notification;
use App\Reminder\Model\Reminder;
use App\Reminder\Model\ReminderType;
use App\Reminder\NotificationRepository;
use App\Tests\Factory\PlantFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class NotificationRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testTheLatestNotificationsFirst(): void
    {
        $reminder = $this->reminder('Rose', ReminderType::Pruning);
        $this->notify($reminder, 'Older', '2026-03-01 07:00');
        $this->notify($reminder, 'Same time, stored first', '2026-04-01 07:00');
        $this->notify($reminder, 'Same time, stored last', '2026-04-01 07:00');
        $this->notify($reminder, 'Newest', '2026-05-01 07:00');

        $repository = self::getContainer()->get(NotificationRepository::class);

        self::assertSame(['Newest', 'Same time, stored last', 'Same time, stored first', 'Older'], self::messages($repository->findLatest()));
        self::assertSame(['Newest', 'Same time, stored last'], self::messages($repository->findLatest(2)));
    }

    public function testTheUnreadOnes(): void
    {
        $reminder = $this->reminder('Rose', ReminderType::Pruning);
        $read = $this->notify($reminder, 'Read', '2026-03-01 07:00');
        $this->notify($reminder, 'Unread', '2026-04-01 07:00');
        $read->markRead(new \DateTimeImmutable('2026-03-01 08:00'));
        $read->markRead(new \DateTimeImmutable('2026-03-02 08:00'));
        $this->entityManager->flush();

        $repository = self::getContainer()->get(NotificationRepository::class);

        self::assertSame(1, $repository->countUnread());
        self::assertSame(['Unread'], self::messages($repository->findUnread()));
        self::assertSame('2026-03-01 08:00', $read->getReadAt()?->format('Y-m-d H:i'), 'Read the first time.');
    }

    private function reminder(string $plantName, ReminderType $type): Reminder
    {
        $plant = PlantFactory::validated($plantName);
        $reminder = $plant->enableReminder($type);
        $this->entityManager->persist($plant);
        $this->entityManager->flush();

        return $reminder;
    }

    private function notify(Reminder $reminder, string $message, string $createdAt): Notification
    {
        $notification = new Notification($reminder, $message, new \DateTimeImmutable($createdAt));
        self::getContainer()->get(NotificationRepository::class)->add($notification);
        $this->entityManager->flush();

        return $notification;
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
