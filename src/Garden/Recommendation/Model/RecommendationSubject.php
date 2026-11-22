<?php

namespace App\Garden\Recommendation\Model;

enum RecommendationSubject: string
{
    case Exposure = 'exposure';
    case Watering = 'watering';
    case Frost = 'frost';
    case Pruning = 'pruning';

    public function label(): string
    {
        return match ($this) {
            self::Exposure => 'Exposure',
            self::Watering => 'Watering',
            self::Frost => 'Frost',
            self::Pruning => 'Pruning',
        };
    }

    /**
     * See templates/components/Icon.html.twig.
     */
    public function pictogram(): string
    {
        return match ($this) {
            self::Exposure => 'sun',
            self::Watering => 'drop',
            self::Frost => 'snowflake',
            self::Pruning => 'secateurs',
        };
    }
}
