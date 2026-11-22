<?php

namespace App\Plant\Model;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

enum Confidence: string implements TranslatableInterface
{
    case High = 'high';
    case Medium = 'medium';
    case Low = 'low';

    public function needsReview(): bool
    {
        return self::High !== $this;
    }

    /**
     * Its label, in translations/messages+intl-icu.*.yaml.
     */
    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('confidence.' . $this->value, locale: $locale);
    }
}
