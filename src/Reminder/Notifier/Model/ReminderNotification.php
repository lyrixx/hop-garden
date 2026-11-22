<?php

namespace App\Reminder\Notifier\Model;

use App\Reminder\Model\Reminder;
use App\Reminder\Model\ReminderType;
use App\Reminder\Notifier\InAppChannel;
use Symfony\Component\Notifier\Notification\Notification;
use Symfony\Component\Notifier\Recipient\RecipientInterface;

final class ReminderNotification extends Notification
{
    public function __construct(
        public readonly Reminder $reminder,
        string $message,
        public readonly \DateTimeImmutable $createdAt,
    ) {
        parent::__construct($message);

        $this->importance(ReminderType::Frost === $reminder->getType() ? self::IMPORTANCE_HIGH : self::IMPORTANCE_MEDIUM);
    }

    public function getChannels(RecipientInterface $recipient): array
    {
        return [InAppChannel::NAME];
    }
}
