<?php

namespace App\Account\Model;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Where an account stands on the waiting list: the administrator approves it, or refuses it, once its email is verified.
 */
enum AccessStatus: string implements TranslatableInterface
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Refused = 'refused';

    /**
     * Its label, in translations/messages+intl-icu.*.yaml.
     */
    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('access_status.' . $this->value, locale: $locale);
    }
}
