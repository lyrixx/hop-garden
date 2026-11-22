<?php

namespace App\Account\Form\Model;

use App\Account\Entity\User;
use Symfony\Component\Security\Core\Validator\Constraints\UserPassword;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * The current password too: a session left open is not enough to take an account over.
 */
final class EmailChangeFormData
{
    #[Assert\NotBlank]
    #[Assert\Email]
    #[Assert\Length(max: 180)]
    public string $newEmail = '';

    #[UserPassword(message: 'account.password.wrong')]
    public string $currentPassword = '';

    public function __construct(
        private readonly string $currentEmail,
    ) {
    }

    #[Assert\Callback]
    public function validateChange(ExecutionContextInterface $context): void
    {
        if (User::normalizeEmail($this->newEmail) === $this->currentEmail) {
            $context->buildViolation('account.email.unchanged')->atPath('newEmail')->addViolation();
        }
    }
}
