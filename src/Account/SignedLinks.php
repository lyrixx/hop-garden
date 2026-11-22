<?php

namespace App\Account;

use App\Account\Entity\User;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The links of the emails that prove an address: signed and dated, checked by UriSigner::verify() in their controller.
 */
final readonly class SignedLinks
{
    public function __construct(
        private UriSigner $uriSigner,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function emailVerification(User $user): string
    {
        return $this->sign('register_verify', ['id' => $user->getId()], new \DateInterval('P2D'));
    }

    /**
     * Bound to the current address too: it stops working once used, or once the email changes another way.
     */
    public function emailChange(User $user, string $newEmail): string
    {
        return $this->sign('account_email_confirm', ['email' => User::normalizeEmail($newEmail), 'from' => $user->getEmail()], new \DateInterval('PT1H'));
    }

    /**
     * @param array<string, int|string> $parameters
     */
    private function sign(string $route, array $parameters, \DateInterval $lifetime): string
    {
        return $this->uriSigner->sign($this->urlGenerator->generate($route, $parameters, UrlGeneratorInterface::ABSOLUTE_URL), $lifetime);
    }
}
