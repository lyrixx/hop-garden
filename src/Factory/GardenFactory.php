<?php

namespace App\Factory;

use App\Account\Entity\User;
use App\Garden\Entity\Garden;
use App\Garden\Entity\GardenLocation;
use Zenstruck\Foundry\Object\Instantiator;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * A garden in Paris, its owner an approved member of their own: ownedBy() for a given one, withMembers() for the
 * others, who join it the day after.
 *
 * @extends PersistentObjectFactory<Garden>
 */
final class GardenFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return Garden::class;
    }

    public function ownedBy(User|UserFactory $owner): static
    {
        return $this->with(['owner' => $owner]);
    }

    public function withMembers(User ...$members): static
    {
        return $this->afterInstantiate(static function (Garden $garden) use ($members): void {
            foreach ($members as $member) {
                $garden->join($member, $garden->getCreatedAt()->modify('+1 day'));
            }
        });
    }

    public function at(GardenLocation $location): static
    {
        return $this->with(['location' => $location]);
    }

    public static function paris(): GardenLocation
    {
        return new GardenLocation('Paris, Île-de-France, France', 48.85341, 2.3488, 'Europe/Paris');
    }

    protected function defaults(): array
    {
        return [
            'name' => 'The garden',
            'location' => self::paris(),
            'owner' => UserFactory::new()->approved(),
            'createdAt' => new \DateTimeImmutable('2026-10-01'),
        ];
    }

    protected function initialize(): static
    {
        return $this->instantiateWith(Instantiator::namedConstructor('create'));
    }
}
