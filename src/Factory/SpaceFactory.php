<?php

namespace App\Factory;

use App\Garden\Entity\Space;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * A spot of the garden whose exposure and soil the user has not told yet.
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
            'name' => 'North bed',
            'exposure' => null,
            'soil' => null,
            'createdAt' => new \DateTimeImmutable('2026-11-01'),
        ];
    }
}
