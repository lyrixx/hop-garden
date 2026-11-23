<?php

namespace App\Account\Security;

use App\Account\Entity\User;
use App\Account\Model\AccessStatus;
use App\Garden\Entity\Invitation;
use App\Garden\Security\InvitationAuthenticator;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Only an approved member gets in. The others are told why, in translations/security.*.yaml. One exception: the login
 * of the link of a pending invitation (InvitationAuthenticator), which lets in an account of the waiting list, and one
 * whose address is the one invited without being verified yet, as it does a new account; the invitation, accepted at
 * once, then admits it (InvitationController::loggedIn()).
 */
final readonly class UserChecker implements UserCheckerInterface
{
    public function __construct(
        private RequestStack $requestStack,
        private ClockInterface $clock,
    ) {
    }

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

        $invitation = $this->invitationOfTheLogin();
        if (!$user->isEmailVerified() && !($invitation?->isSentTo($user) ?? false)) {
            throw new CustomUserMessageAccountStatusException('login.unverified');
        }

        match ($user->getAccessStatus()) {
            AccessStatus::Pending => null === $invitation ? throw new CustomUserMessageAccountStatusException('login.pending') : null,
            AccessStatus::Refused => throw new CustomUserMessageAccountStatusException('login.refused'),
            AccessStatus::Approved => null,
        };
    }

    /**
     * Pending now: neither expired, revoked nor used. Null for any other login.
     */
    private function invitationOfTheLogin(): ?Invitation
    {
        $invitation = $this->requestStack->getMainRequest()?->attributes->get(InvitationAuthenticator::INVITATION);

        return $invitation instanceof Invitation && $invitation->isPending($this->clock->now()) ? $invitation : null;
    }
}
