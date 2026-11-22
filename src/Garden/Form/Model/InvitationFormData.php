<?php

namespace App\Garden\Form\Model;

use App\Account\Entity\User;
use App\Garden\Entity\Garden;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * The address its owner invites into a garden. Whether it has an account is never said: only whether a member of the
 * garden has it.
 */
final class InvitationFormData
{
    #[Assert\NotBlank]
    #[Assert\Email]
    #[Assert\Length(max: 180)]
    public string $email = '';

    public function __construct(
        private readonly Garden $garden,
    ) {
    }

    #[Assert\Callback]
    public function validateNotAMember(ExecutionContextInterface $context): void
    {
        foreach ($this->garden->getMembers() as $member) {
            if ($member->getEmail() === User::normalizeEmail($this->email)) {
                $context->buildViolation('invitation.already_member')->atPath('email')->setParameter('{{ name }}', $member->getDisplayName())->addViolation();
            }
        }
    }
}
