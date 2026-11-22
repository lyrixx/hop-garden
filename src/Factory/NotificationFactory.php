<?php

namespace App\Factory;

use App\Reminder\Entity\Notification;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * An unread notification of a pruning reminder.
 *
 * @extends PersistentObjectFactory<Notification>
 */
final class NotificationFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return Notification::class;
    }

    public function read(\DateTimeImmutable $readAt): static
    {
        return $this->afterInstantiate(static function (Notification $notification) use ($readAt): void {
            $notification->markRead($readAt);
        });
    }

    protected function defaults(): array
    {
        return [
            'reminder' => ReminderFactory::new(),
            'message' => 'Time to prune Bigleaf hydrangea.',
            'createdAt' => new \DateTimeImmutable('2026-11-01 07:00'),
        ];
    }
}
