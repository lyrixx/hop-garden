<?php

namespace App\Plant\Model;

enum Exposure: string
{
    case Sun = 'sun';
    case PartShade = 'part_shade';
    case Shade = 'shade';

    public function label(): string
    {
        return match ($this) {
            self::Sun => 'Full sun',
            self::PartShade => 'Part shade',
            self::Shade => 'Shade',
        };
    }
}
