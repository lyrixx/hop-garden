<?php

namespace App\Reminder\Model;

use App\Plant\Entity\CareSheet;

enum ReminderType: string
{
    case Pruning = 'pruning';
    case Deadheading = 'deadheading';
    case Cutting = 'cutting';
    case Frost = 'frost';

    public function label(): string
    {
        return match ($this) {
            self::Pruning => 'Pruning',
            self::Deadheading => 'Deadheading',
            self::Cutting => 'Cuttings',
            self::Frost => 'Frost alert',
        };
    }

    /**
     * See templates/components/Icon.html.twig.
     */
    public function pictogram(): string
    {
        return match ($this) {
            self::Pruning => 'secateurs',
            self::Deadheading => 'flower',
            self::Cutting => 'sprout',
            self::Frost => 'snowflake',
        };
    }

    public function isCalendar(): bool
    {
        return self::Frost !== $this;
    }

    /**
     * @return list<int>
     */
    public function monthsIn(CareSheet $careSheet): array
    {
        return match ($this) {
            self::Pruning => $careSheet->getPruningMonths(),
            self::Deadheading => $careSheet->getDeadheadingMonths(),
            self::Cutting => $careSheet->getCuttingMonths(),
            self::Frost => [],
        };
    }

    /**
     * @return list<self>
     */
    public static function calendarTypes(): array
    {
        return [self::Pruning, self::Deadheading, self::Cutting];
    }
}
