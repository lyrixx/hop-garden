<?php

namespace App\Reminder\Notifier;

use App\Locale\PreferredLocale;
use App\Mercure\Topics;
use App\Reminder\Entity\Notification;
use App\Reminder\NotificationRepository;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Events;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\Exception\RuntimeException as MercureException;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Translation\LocaleSwitcher;
use Symfony\Contracts\Service\ResetInterface;
use Twig\Environment;

/**
 * Pushes a toast to the browsers of its recipient once a notification is stored, never before: a toast must point to
 * a notification that exists. The toast and the unread count of the top bar go as Turbo Streams, on the private topic
 * of the notifications of the recipient in its garden, rendered in their language: one flush sends the toasts of every
 * member, each in theirs.
 */
#[AsEntityListener(event: Events::postPersist, entity: Notification::class)]
#[AsDoctrineListener(event: Events::postFlush)]
final class NotificationToastPublisher implements ResetInterface
{
    /** @var list<Notification> */
    private array $pending = [];

    public function __construct(
        private readonly HubInterface $hub,
        private readonly NotificationRepository $notificationRepository,
        private readonly Environment $twig,
        private readonly LoggerInterface $logger,
        private readonly Topics $topics,
        private readonly LocaleSwitcher $localeSwitcher,
        private readonly PreferredLocale $preferredLocale,
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

        foreach ($notifications as $notification) {
            $recipient = $notification->getRecipient();
            $garden = $notification->getGarden();
            $toast = $this->localeSwitcher->runWithLocale($this->preferredLocale->of($recipient)->value, fn (): string => $this->twig->render('notification/toast.stream.html.twig', [
                'notification' => $notification,
                'unread' => $this->notificationRepository->countUnread($recipient, $garden),
            ]));

            try {
                $this->hub->publish(new Update($this->topics->notifications($recipient, $garden), $toast, private: true));
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
