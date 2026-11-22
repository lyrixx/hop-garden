<?php

namespace App\Account;

use App\Account\Entity\User;
use App\Locale\Model\SupportedLocale;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The emails of the accounts, in templates/email/: each in one language, the one of the page that sends it, or the
 * preferred one of its recipient when somebody else sends it (the administrator). Sent at once, after the flush.
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

    public function sendEmailVerification(User $user, SupportedLocale $locale): void
    {
        $this->send($user->getEmail(), 'verify_email', $locale, [
            'name' => $user->getDisplayName(),
            'url' => $this->signedLinks->emailVerification($user),
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

    public function sendEmailChange(User $user, string $newEmail, SupportedLocale $locale): void
    {
        $this->send(User::normalizeEmail($newEmail), 'confirm_new_email', $locale, [
            'name' => $user->getDisplayName(),
            'url' => $this->signedLinks->emailChange($user, $newEmail),
        ]);
    }

    /**
     * @param array<string, string> $context
     */
    private function send(string $to, string $template, SupportedLocale $locale, array $context): void
    {
        $this->mailer->send(
            new TemplatedEmail()
                ->to($to)
                ->subject($this->translator->trans("email.{$template}.subject", locale: $locale->value))
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
