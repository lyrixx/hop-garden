<?php

namespace App\Account\Form\Model;

use App\Account\Entity\User;
use App\Account\Validator\NewPassword;
use App\Locale\Model\SupportedLocale;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * No UniqueEntity on the email: the page would tell whether an address has an account.
 */
final class RegistrationFormData
{
    #[Assert\NotBlank]
    #[Assert\Email]
    #[Assert\Length(max: 180)]
    public string $email = '';

    #[Assert\NotBlank(normalizer: 'trim')]
    #[Assert\Length(max: 100)]
    public string $displayName = '';

    #[NewPassword]
    public string $plainPassword = '';

    #[Assert\IsTrue(message: 'registration.terms.required')]
    public bool $acceptsTerms = false;

    public function toUser(string $passwordHash, ?SupportedLocale $preferredLocale, \DateTimeImmutable $registeredAt): User
    {
        return User::register($this->email, trim($this->displayName), $passwordHash, $preferredLocale, $registeredAt);
    }
}
