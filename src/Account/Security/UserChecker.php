<?php

namespace App\Account\Security;

use App\Account\Entity\User;
use App\Account\Model\AccessStatus;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Only an approved member gets in. The others are told why, in translations/security.*.yaml.
 */
final readonly class UserChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user): void
    {
    }

    /**
     * After the password: only who knows it learns where the account stands.
     */
    public function checkPostAuth(UserInterface $user, ?TokenInterface $token = null): void
    {
        if (!$user instanceof User) {
            return;
        }

        if (!$user->isEmailVerified()) {
            throw new CustomUserMessageAccountStatusException('login.unverified');
        }

        match ($user->getAccessStatus()) {
            AccessStatus::Pending => throw new CustomUserMessageAccountStatusException('login.pending'),
            AccessStatus::Refused => throw new CustomUserMessageAccountStatusException('login.refused'),
            AccessStatus::Approved => null,
        };
    }
}
