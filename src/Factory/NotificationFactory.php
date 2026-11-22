<?php

namespace App\Factory;

use App\Reminder\Entity\Notification;
use App\Reminder\Entity\Reminder;
use Zenstruck\Foundry\Factory;
use Zenstruck\Foundry\Object\Instantiator;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * An unread notification of a pruning reminder, to the owner of its garden unless told.
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

    protected function initialize(): static
    {
        return $this->instantiateWith(Instantiator::namedConstructor('remind'))->beforeInstantiate(static function (array $attributes): array {
            // Made first, for the owner of its garden
            if (($attributes['reminder'] ?? null) instanceof Factory) {
                $attributes['reminder'] = $attributes['reminder']->create();
            }
            $reminder = $attributes['reminder'] ?? null;
            if (!isset($attributes['recipient']) && $reminder instanceof Reminder) {
                $attributes['recipient'] = $reminder->getPlant()->getGarden()->getOwner();
            }

            return $attributes;
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
