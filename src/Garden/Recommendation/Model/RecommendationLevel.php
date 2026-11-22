<?php

namespace App\Garden\Recommendation\Model;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * From the most to the least pressing.
 */
enum RecommendationLevel: string implements TranslatableInterface
{
    case Warning = 'warning';
    case Action = 'action';
    case Info = 'info';
    case Ok = 'ok';

    /**
     * Its label, in translations/messages+intl-icu.*.yaml.
     */
    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('recommendation_level.' . $this->value, locale: $locale);
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
