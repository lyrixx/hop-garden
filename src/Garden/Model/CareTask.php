<?php

namespace App\Garden\Model;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

enum CareTask: string implements TranslatableInterface
{
    case Pruning = 'pruning';
    case FrostProtection = 'frost_protection';
    case Watering = 'watering';
    case Feeding = 'feeding';
    case Mulching = 'mulching';

    /**
     * Its label, in translations/messages+intl-icu.*.yaml.
     */
    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('care_task.' . $this->value, locale: $locale);
    }
}
