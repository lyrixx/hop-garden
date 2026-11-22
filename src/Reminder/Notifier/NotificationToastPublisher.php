<?php

namespace App\Reminder\Notifier;

use App\Reminder\Model\Notification;
use App\Reminder\NotificationRepository;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Events;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\Exception\RuntimeException as MercureException;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Contracts\Service\ResetInterface;
use Twig\Environment;

/**
 * Pushes a toast to the browsers once a notification is stored, never before: a toast must point to
 * a notification that exists. The toast and the unread count of the top bar go as Turbo Streams.
 */
#[AsEntityListener(event: Events::postPersist, entity: Notification::class)]
#[AsDoctrineListener(event: Events::postFlush)]
final class NotificationToastPublisher implements ResetInterface
{
    public const string TOPIC = 'notifications';

    /** @var list<Notification> */
    private array $pending = [];

    public function __construct(
        private readonly HubInterface $hub,
        private readonly NotificationRepository $notificationRepository,
        private readonly Environment $twig,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function postPersist(Notification $notification): void
    {
        $this->pending[] = $notification;
    }

    public function postFlush(): void
    {
        if ([] === $this->pending) {
            return;
        }

        $notifications = $this->pending;
        $this->pending = [];
        $unreadCount = $this->notificationRepository->countUnread();

        foreach ($notifications as $notification) {
            try {
                $this->hub->publish(new Update(self::TOPIC, $this->twig->render('notification/toast.stream.html.twig', [
                    'notification' => $notification,
                    'unread' => $unreadCount,
                ])));
            } catch (MercureException $e) {
                // The notification is stored anyway: it shows up on the next page load
                $this->logger->warning('Could not push the notification toast.', ['exception' => $e]);
            }
        }
    }

    public function reset(): void
    {
        $this->pending = [];
    }
}
