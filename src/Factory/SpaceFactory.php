<?php

namespace App\Factory;

use App\Garden\Entity\Space;
use App\Story\DefaultGardenStory;
use Zenstruck\Foundry\LazyValue;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * A spot of the garden whose exposure and soil the user has not told yet, in the default garden of the test unless
 * told (DefaultGardenStory).
 *
 * @extends PersistentObjectFactory<Space>
 */
final class SpaceFactory extends PersistentObjectFactory
{
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
}
