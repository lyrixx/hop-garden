<?php

namespace App\Factory;

use App\Plant\Entity\Plant;
use App\Reminder\Model\ReminderType;
use App\Story\DefaultGardenStory;
use Zenstruck\Foundry\LazyValue;
use Zenstruck\Foundry\Object\Instantiator;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * A draft, as the agent proposes it: validated() for a plant of the garden. In the default garden of the test unless
 * told (DefaultGardenStory).
 *
 * @extends PersistentObjectFactory<Plant>
 */
final class PlantFactory extends PersistentObjectFactory
{
    // Before the states that need a validated plant, whatever their order
    private const int VALIDATION_PRIORITY = 10;

    public static function class(): string
    {
        return Plant::class;
    }

    /**
     * The user validates it, by default five minutes after it was proposed.
     */
    public function validated(?\DateTimeImmutable $validatedAt = null): static
    {
        return $this->afterInstantiate(
            static function (Plant $plant) use ($validatedAt): void {
                $plant->validate($validatedAt ?? $plant->getCreatedAt()->modify('+5 minutes'));
            },
            self::VALIDATION_PRIORITY,
        );
    }

    /**
     * Only for a validated plant, as Plant::enableReminder() requires.
     */
    public function withEnabledReminders(ReminderType ...$types): static
    {
        return $this->afterInstantiate(static function (Plant $plant) use ($types): void {
            foreach ($types as $type) {
                $plant->enableReminder($type);
            }
        });
    }

    protected function defaults(): array
    {
        return [
            'garden' => LazyValue::new(static fn () => DefaultGardenStory::get('garden')),
            'commonName' => 'Bigleaf hydrangea',
            'scientificName' => 'Hydrangea macrophylla',
            'family' => 'Hydrangeaceae',
            'careSheet' => CareSheetFactory::new(),
            'createdAt' => new \DateTimeImmutable('2026-11-01 10:00'),
        ];
    }

    protected function initialize(): static
    {
        return $this->instantiateWith(Instantiator::namedConstructor('propose'));
    }
}
