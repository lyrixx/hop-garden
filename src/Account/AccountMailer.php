<?php

namespace App\Account;

use App\Account\Entity\User;
use App\Account\Model\AccessStatus;
use App\Garden\Model\InvitationLink;
use App\Locale\Model\SupportedLocale;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The emails of the accounts, in templates/email/: each in one language, the one of the page that sends it, or the
 * preferred one of its recipient when somebody else sends it (the administrator, a member who invites). Sent at once,
 * after the flush.
 */
final readonly class AccountMailer
{
    public function __construct(
        private MailerInterface $mailer,
        private TranslatorInterface $translator,
        private UrlGeneratorInterface $urlGenerator,
        private SignedLinks $signedLinks,
    ) {
    }

    /**
     * Then the waiting list, unless an invitation let them in already.
     */
    public function sendEmailVerification(User $user, SupportedLocale $locale): void
    {
        $this->send($user->getEmail(), 'verify_email', $locale, [
            'name' => $user->getDisplayName(),
            'url' => $this->signedLinks->emailVerification($user),
            'admitted' => AccessStatus::Approved === $user->getAccessStatus(),
        ]);
    }

    /**
     * To the owner of an address that somebody registered again, or wants for another account: the page says the same
     * whether the address is known or not, this email tells its owner.
     */
    public function sendAddressInUse(User $owner, SupportedLocale $locale): void
    {
        $this->send($owner->getEmail(), 'address_in_use', $owner->getPreferredLocale() ?? $locale, [
            'name' => $owner->getDisplayName(),
            'login_url' => $this->url('login'),
            'reset_url' => $this->url('reset_password_request'),
        ]);
    }

    public function sendAccessApproved(User $user): void
    {
        $this->send($user->getEmail(), 'access_approved', $user->getPreferredLocale() ?? SupportedLocale::DEFAULT, [
            'name' => $user->getDisplayName(),
            'login_url' => $this->url('login'),
        ]);
    }

    public function sendAccessRefused(User $user): void
    {
        $this->send($user->getEmail(), 'access_refused', $user->getPreferredLocale() ?? SupportedLocale::DEFAULT, [
            'name' => $user->getDisplayName(),
        ]);
    }

    public function sendPasswordReset(User $user, #[\SensitiveParameter] string $token, SupportedLocale $locale): void
    {
        $this->send($user->getEmail(), 'reset_password', $user->getPreferredLocale() ?? $locale, [
            'name' => $user->getDisplayName(),
            'url' => $this->url('reset_password_token', ['token' => $token]),
        ]);
    }

    /**
     * To the address invited into a garden: its link, valid until the date it expires in the timezone of the garden.
     */
    public function sendInvitation(InvitationLink $link, SupportedLocale $locale): void
    {
        $invitation = $link->invitation;
        $garden = $invitation->getGarden();

        $names = ['inviter' => $invitation->getInvitedBy()->getDisplayName(), 'garden' => $garden->getName()];
        $this->send($invitation->getEmail(), 'invitation', $locale, [
            ...$names,
            'url' => $this->url('invitation', ['token' => $link->token]),
            'expires_at' => $garden->localTime($invitation->getExpiresAt()),
        ], $names);
    }

    public function sendEmailChange(User $user, string $newEmail, SupportedLocale $locale): void
    {
        $this->send(User::normalizeEmail($newEmail), 'confirm_new_email', $locale, [
            'name' => $user->getDisplayName(),
            'url' => $this->signedLinks->emailChange($user, $newEmail),
        ]);
    }

    /**
     * @param array<string, string|bool|\DateTimeImmutable> $context
     * @param array<string, string>                         $subjectParameters
     */
    private function send(string $to, string $template, SupportedLocale $locale, array $context, array $subjectParameters = []): void
    {
        $this->mailer->send(
            new TemplatedEmail()
                ->to($to)
                ->subject($this->translator->trans("email.{$template}.subject", $subjectParameters, locale: $locale->value))
                ->htmlTemplate("email/{$template}.html.twig")
                ->locale($locale->value)
                ->context(['locale' => $locale->value, ...$context]),
        );
    }

    /**
     * @param array<string, string> $parameters
     */
    private function url(string $route, array $parameters = []): string
    {
        return $this->urlGenerator->generate($route, $parameters, UrlGeneratorInterface::ABSOLUTE_URL);
    }
}
