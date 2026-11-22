<?php

namespace App\Plant\Model;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

enum Watering: string implements TranslatableInterface
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';

    /**
     * Its label, in translations/messages+intl-icu.*.yaml.
     */
    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('watering.' . $this->value, locale: $locale);
    }
}
