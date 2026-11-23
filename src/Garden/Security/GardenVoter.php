<?php

namespace App\Garden\Security;

use App\Account\Entity\User;
use App\Garden\CurrentGarden;
use App\Garden\Entity\Garden;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Its members view and edit a garden, its owner manages it: its name, its place, its invitations and its members. A
 * token bound to a garden (CurrentGarden::TOKEN_ATTRIBUTE) gets no other one, whatever its user belongs to.
 *
 * @extends Voter<string, Garden>
 */
final class GardenVoter extends Voter
{
    public const string VIEW = 'GARDEN_VIEW';
    public const string EDIT = 'GARDEN_EDIT';
    public const string MANAGE = 'GARDEN_MANAGE';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, [self::VIEW, self::EDIT, self::MANAGE], true) && $subject instanceof Garden;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        if ($token->hasAttribute(CurrentGarden::TOKEN_ATTRIBUTE) && $token->getAttribute(CurrentGarden::TOKEN_ATTRIBUTE) !== $subject->getId()->toRfc4122()) {
            return false;
        }

        return self::MANAGE === $attribute ? $subject->isOwnedBy($user) : $subject->isMember($user);
    }
}
