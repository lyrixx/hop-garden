<?php

namespace App\Plant\Model;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

enum Exposure: string implements TranslatableInterface
{
    case Sun = 'sun';
    case PartShade = 'part_shade';
    case Shade = 'shade';

    /**
     * Its label, in translations/messages+intl-icu.*.yaml.
     */
    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('exposure.' . $this->value, locale: $locale);
    }
}
