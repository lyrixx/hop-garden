<?php

namespace App\Factory;

use App\Garden\Entity\Space;
use App\Story\DefaultGardenStory;
use Zenstruck\Foundry\LazyValue;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * A spot of the garden whose exposure and soil the user has not told yet, in the default garden of the test unless
 * told (DefaultGardenStory). Its slug comes from its name, unless told.
 *
 * @extends PersistentObjectFactory<Space>
 */
final class SpaceFactory extends PersistentObjectFactory
{
    use FreeSlug;

    public static function class(): string
    {
        return Space::class;
    }

    protected function defaults(): array
    {
        return [
            'garden' => LazyValue::new(static fn () => DefaultGardenStory::get('garden')),
            'name' => 'North bed',
            'exposure' => null,
            'soil' => null,
            'createdAt' => new \DateTimeImmutable('2026-11-01'),
        ];
    }

    protected function initialize(): static
    {
        return $this->beforeInstantiate(static function (array $attributes): array {
            $attributes['garden'] = self::gardenOf($attributes['garden'] ?? null);
            $attributes['slug'] ??= self::freeSlug($attributes['name'] ?? null, static fn (): array => array_map(static fn (Space $space): string => $space->getSlug(), static::repository()->findBy(['garden' => $attributes['garden']])), $attributes['garden']);

            return $attributes;
        });
    }
}
