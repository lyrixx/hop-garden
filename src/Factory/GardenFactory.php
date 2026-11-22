<?php

namespace App\Factory;

use App\Account\Entity\User;
use App\Garden\Entity\Garden;
use App\Garden\Entity\GardenLocation;
use Zenstruck\Foundry\Object\Instantiator;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * A garden in Paris, its owner an approved member of their own: ownedBy() for a given one, withMembers() for the
 * others, who join it the day after. Its slug comes from its name, unless told.
 *
 * @extends PersistentObjectFactory<Garden>
 */
final class GardenFactory extends PersistentObjectFactory
{
    use FreeSlug;

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
        return $this
            ->beforeInstantiate(static function (array $attributes): array {
                $attributes['slug'] ??= self::freeSlug($attributes['name'] ?? null, static fn (): array => array_map(static fn (Garden $garden): string => $garden->getSlug(), static::repository()->findAll()));

                return $attributes;
            })
            ->instantiateWith(Instantiator::namedConstructor('create'))
        ;
    }
}
