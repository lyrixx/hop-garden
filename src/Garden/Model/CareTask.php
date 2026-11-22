<?php

namespace App\Garden\Model;

enum CareTask: string
{
    case Pruning = 'pruning';
    case FrostProtection = 'frost_protection';
    case Watering = 'watering';
    case Feeding = 'feeding';
    case Mulching = 'mulching';

    public function label(): string
    {
        return match ($this) {
            self::Pruning => 'Pruned',
            self::FrostProtection => 'Sheltered from frost',
            self::Watering => 'Watered',
            self::Feeding => 'Fed',
            self::Mulching => 'Mulched',
        };
    }
}
