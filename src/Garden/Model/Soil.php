<?php

namespace App\Garden\Model;

enum Soil: string
{
    case Chalky = 'chalky';
    case Clay = 'clay';
    case Loamy = 'loamy';
    case Peaty = 'peaty';
    case Sandy = 'sandy';
    case Silty = 'silty';

    public function label(): string
    {
        return match ($this) {
            self::Chalky => 'Chalky',
            self::Clay => 'Clay',
            self::Loamy => 'Loamy',
            self::Peaty => 'Peaty',
            self::Sandy => 'Sandy',
            self::Silty => 'Silty',
        };
    }
}
