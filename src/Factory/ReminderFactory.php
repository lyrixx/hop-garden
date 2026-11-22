<?php

namespace App\Factory;

use App\Plant\Entity\Plant;
use App\Reminder\Entity\Reminder;
use App\Reminder\Model\ReminderType;
use Zenstruck\Foundry\Object\Instantiator;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * A reminder the user enabled on a validated plant: Plant::enableReminder() creates it.
 *
 * @extends PersistentObjectFactory<Reminder>
 */
final class ReminderFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return Reminder::class;
    }

    public function disabled(): static
    {
        return $this->afterInstantiate(static function (Reminder $reminder): void {
            $reminder->disable();
        });
    }

    protected function defaults(): array
    {
        return [
            'plant' => PlantFactory::new()->validated(),
            'type' => ReminderType::Pruning,
        ];
    }

    protected function initialize(): static
    {
        return $this->instantiateWith(Instantiator::use(static fn (Plant $plant, ReminderType $type): Reminder => $plant->enableReminder($type)));
    }
}
