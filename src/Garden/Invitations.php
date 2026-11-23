<?php

namespace App\Garden;

use App\Account\AccountMailer;
use App\Account\Entity\User;
use App\Account\UserRepository;
use App\Garden\Entity\Garden;
use App\Garden\Entity\Invitation;
use App\Garden\Model\InvitationLink;
use App\Locale\PreferredLocale;
use App\Reminder\Entity\Notification;
use App\Reminder\NotificationRepository;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The invitations of a garden: an address is invited by email, with a link its owner may send another way too. Each new
 * link replaces the one before, the database keeping its hash only. The caller flushes, then sends the email.
 */
final readonly class Invitations
{
    public function __construct(
        private InvitationRepository $invitationRepository,
        private NotificationRepository $notificationRepository,
        private UserRepository $userRepository,
        private AccountMailer $accountMailer,
        private PreferredLocale $preferredLocale,
        private TranslatorInterface $translator,
        private ClockInterface $clock,
    ) {
    }

    /**
     * A new invitation, or the open one of this address with a new link: one per address in a garden.
     */
    public function invite(Garden $garden, string $email, User $invitedBy): InvitationLink
    {
        $open = $this->invitationRepository->findOpen($garden, $email);
        if (null !== $open) {
            return $this->renew($open);
        }

        $token = self::newToken();
        $invitation = Invitation::send($garden, $email, $invitedBy, $token, $this->clock->now());
        $this->invitationRepository->add($invitation);

        return new InvitationLink($invitation, $token);
    }

    /**
     * The link sent before stops working.
     */
    public function renew(Invitation $invitation): InvitationLink
    {
        $token = self::newToken();
        $invitation->renew($token, $this->clock->now());

        return new InvitationLink($invitation, $token);
    }

    /**
     * In the language of the account of the address, if it has one and chose it, else in the one of the page that
     * invites. Once flushed.
     */
    public function sendEmail(InvitationLink $link): void
    {
        $invitee = $this->userRepository->findOneByEmail($link->invitation->getEmail());

        $this->accountMailer->sendInvitation($link, $invitee?->getPreferredLocale() ?? $this->preferredLocale->get());
    }

    /**
     * The user joins its garden, and who invited them is told, in their language (its owner if they left it). In a
     * transaction, flushed before it commits: the invitation stays locked, a second click or a second person with the
     * link waits, then finds it accepted.
     *
     * @return bool false when it is not pending any more, or they are a member already: nothing changes
     */
    public function accept(Invitation $invitation, User $user): bool
    {
        $this->invitationRepository->lock($invitation);
        $now = $this->clock->now();
        $garden = $invitation->getGarden();
        if (!$invitation->isPending($now) || $garden->isMember($user)) {
            return false;
        }

        $invitation->accept($user, $now);

        $inviter = $invitation->getInvitedBy();
        $recipient = $garden->isMember($inviter) ? $inviter : $garden->getOwner();
        $message = $this->translator->trans(
            $invitation->isSentTo($user) ? 'notification.new_member.message' : 'notification.new_member.other_address',
            ['name' => $user->getDisplayName(), 'garden' => $garden->getName(), 'email' => $invitation->getEmail()],
            locale: $this->preferredLocale->of($recipient)->value,
        );
        $this->notificationRepository->add(Notification::newMember($recipient, $invitation, $message, $now));

        return true;
    }

    /**
     * 256 bits of random_bytes(), in base64url: 43 characters (InvitationController::TOKEN_REQUIREMENT).
     */
    private static function newToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}
