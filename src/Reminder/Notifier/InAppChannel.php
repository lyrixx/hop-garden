<?php

namespace App\Reminder\Notifier;

use App\Reminder\Entity\Notification;
use App\Reminder\NotificationRepository;
use App\Reminder\Notifier\Model\MemberRecipient;
use App\Reminder\Notifier\Model\ReminderNotification;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\Notifier\Channel\ChannelInterface;
use Symfony\Component\Notifier\Notification\Notification as NotifierNotification;
use Symfony\Component\Notifier\Recipient\RecipientInterface;

/**
 * Stores the notification of a member for their "Notifications" page. The toast is pushed once it is flushed,
 * see NotificationToastPublisher.
 */
#[AutoconfigureTag('notifier.channel', ['channel' => self::NAME])]
final readonly class InAppChannel implements ChannelInterface
{
    public const string NAME = 'in_app';

    public function __construct(
        private NotificationRepository $notificationRepository,
    ) {
    }

    public function notify(NotifierNotification $notification, RecipientInterface $recipient, ?string $transportName = null): void
    {
        if (!$notification instanceof ReminderNotification || !$recipient instanceof MemberRecipient) {
            throw new \LogicException(\sprintf('The "%s" channel only sends reminder notifications to members, got "%s" for "%s".', self::NAME, $notification::class, $recipient::class));
        }

        $this->notificationRepository->add(Notification::remind($recipient->user, $notification->reminder, $notification->getSubject(), $notification->createdAt));
    }

    public function supports(NotifierNotification $notification, RecipientInterface $recipient): bool
    {
        return $notification instanceof ReminderNotification && $recipient instanceof MemberRecipient;
    }
}
