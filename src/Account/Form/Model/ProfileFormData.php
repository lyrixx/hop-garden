<?php

namespace App\Account\Form\Model;

use App\Account\Entity\User;
use App\Locale\Model\SupportedLocale;
use Symfony\Component\Validator\Constraints as Assert;

final class ProfileFormData
{
    #[Assert\NotBlank(normalizer: 'trim')]
    #[Assert\Length(max: 100)]
    public string $displayName = '';

    #[Assert\NotNull]
    public ?SupportedLocale $preferredLocale = null;

    /**
     * @param SupportedLocale $currentLocale the language of the interface, until they choose one
     */
    public static function fromUser(User $user, SupportedLocale $currentLocale): self
    {
        $data = new self();
        $data->displayName = $user->getDisplayName();
        $data->preferredLocale = $user->getPreferredLocale() ?? $currentLocale;

        return $data;
    }
}
