<?php

namespace App\Garden\Recommendation\Model;

/**
 * From the most to the least pressing.
 */
enum RecommendationLevel: string
{
    case Warning = 'warning';
    case Action = 'action';
    case Info = 'info';
    case Ok = 'ok';

    public function label(): string
    {
        return match ($this) {
            self::Warning => 'Warning',
            self::Action => 'To do',
            self::Info => 'Advice',
            self::Ok => 'All good',
        };
    }

    public function priority(): int
    {
        return match ($this) {
            self::Warning => 0,
            self::Action => 1,
            self::Info => 2,
            self::Ok => 3,
        };
    }
}
