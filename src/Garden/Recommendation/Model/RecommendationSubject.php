<?php

namespace App\Garden\Recommendation\Model;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

enum RecommendationSubject: string implements TranslatableInterface
{
    case Exposure = 'exposure';
    case Watering = 'watering';
    case Frost = 'frost';
    case Pruning = 'pruning';

    /**
     * Its label, in translations/messages+intl-icu.*.yaml.
     */
    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('recommendation_subject.' . $this->value, locale: $locale);
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
