<?php

namespace App\Garden\Model;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * What a member may do in a garden: every member views and edits it, its owner manages it too (App\Garden\Security\GardenVoter).
 */
enum MembershipRole: string implements TranslatableInterface
{
    case Owner = 'owner';
    case Member = 'member';

    /**
     * Its label, in translations/messages+intl-icu.*.yaml.
     */
    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('membership_role.' . $this->value, locale: $locale);
    }
}
