<?php

namespace App\Reminder\Model;

use App\Plant\Entity\CareSheet;
use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

enum ReminderType: string implements TranslatableInterface
{
    case Pruning = 'pruning';
    case Deadheading = 'deadheading';
    case Cutting = 'cutting';
    case Frost = 'frost';

    /**
     * Its label, in translations/messages+intl-icu.*.yaml.
     */
    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('reminder_type.' . $this->value, locale: $locale);
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
