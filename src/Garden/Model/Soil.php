<?php

namespace App\Garden\Model;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

enum Soil: string implements TranslatableInterface
{
    case Chalky = 'chalky';
    case Clay = 'clay';
    case Loamy = 'loamy';
    case Peaty = 'peaty';
    case Sandy = 'sandy';
    case Silty = 'silty';

    /**
     * Its label, in translations/messages+intl-icu.*.yaml.
     */
    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('soil.' . $this->value, locale: $locale);
    }
}
