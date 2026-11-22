<?php

namespace App\Tests\Functional\Controller;

use App\Garden\Model\Planting;
use App\Reminder\Model\Notification;
use App\Reminder\Model\ReminderType;
use App\Reminder\NotificationRepository;
use App\Tests\Factory\PlantFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

final class NotificationControllerTest extends WebTestCase
{
    use ClockSensitiveTrait;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    public function testNothingYet(): void
    {
        $this->client->request('GET', '/notifications');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.callout', 'Nothing yet. Reminders are checked every day at 7:00.');
        self::assertSelectorExists('.nav .badge[hidden]');
    }

    public function testTheNotificationsAreListedThenMarkedRead(): void
    {
        self::mockTime('2026-12-01 09:30');
        $rose = PlantFactory::validated('Rose');
        $pruning = $rose->enableReminder(ReminderType::Pruning);
        $frost = $rose->enableReminder(ReminderType::Frost);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($rose);
        $gate = Planting::forValidatedPlant($rose);
        $entityManager->persist($gate);
        $read = new Notification($pruning, 'Time to prune Rose.', new \DateTimeImmutable('2026-11-01 07:00'));
        $read->markRead(new \DateTimeImmutable('2026-11-01 08:00'));
        $entityManager->persist($read);
        $entityManager->persist(new Notification($frost, 'Frost expected on Tuesday 1 December (-3.2 °C): cover Rose.', new \DateTimeImmutable('2026-12-01 07:00')));
        $entityManager->flush();

        $this->client->request('GET', '/garden');
        self::assertSelectorTextSame('.nav .badge', '1');

        $crawler = $this->client->request('GET', '/notifications');

        self::assertResponseIsSuccessful();
        self::assertSame(
            [['Frost expected on Tuesday 1 December (-3.2 °C): cover Rose.', true], ['Time to prune Rose.', false]],
            $crawler->filter('.notification')->each(static fn ($notification): array => [$notification->filter('p')->first()->text(), str_contains((string) $notification->attr('class'), 'is-unread')]),
            'Newest first; the unread one stands out on this visit.',
        );
        self::assertSelectorTextContains('.notification-frost .meta', 'Rose · Tuesday 1 December, 07:00');
        self::assertSelectorExists('.notification-frost .meta a[href="/garden/' . $gate->getId() . '"]', 'My rose, in the garden.');

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $notifications = self::getContainer()->get(NotificationRepository::class)->findLatest();
        self::assertSame('2026-12-01 09:30', $notifications[0]->getReadAt()?->format('Y-m-d H:i'), 'Read now.');
        self::assertSame('2026-11-01 08:00', $notifications[1]->getReadAt()?->format('Y-m-d H:i'), 'Read before.');

        $crawler = $this->client->request('GET', '/notifications');
        self::assertCount(0, $crawler->filter('.notification.is-unread'));
        self::assertSelectorExists('.nav .badge[hidden]');
    }
}
