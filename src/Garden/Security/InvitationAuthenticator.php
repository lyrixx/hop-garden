<?php

namespace App\Garden\Security;

use App\Garden\InvitationRepository;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\InteractiveAuthenticatorInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\CsrfTokenBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\PasswordCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\SecurityRequestAttributes;

/**
 * The login of the link of an invitation, checked as form_login checks its own: the password, the CSRF token, and the
 * login_throttling of the firewall, which counts every authenticator of it. Its invitation goes on the request
 * (INVITATION) for App\Account\Security\UserChecker, which lets an account in for a pending one only; then the request
 * goes on to InvitationController::loggedIn(), which accepts it.
 */
final class InvitationAuthenticator extends AbstractAuthenticator implements InteractiveAuthenticatorInterface
{
    /**
     * The invitation of the link, or null: a request attribute that only this authenticator sets.
     */
    public const string INVITATION = '_invitation';

    /**
     * Its form posts to this route: templates/invitation/login.html.twig.
     */
    private const string ROUTE = 'invitation_login_check';

    public function __construct(
        private readonly InvitationRepository $invitationRepository,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function supports(Request $request): bool
    {
        return self::ROUTE === $request->attributes->get('_route');
    }

    public function authenticate(Request $request): Passport
    {
        $email = $request->getPayload()->getString('_username');
        $request->getSession()->set(SecurityRequestAttributes::LAST_USERNAME, $email);
        $request->attributes->set(self::INVITATION, $this->invitationRepository->findOneByToken($request->attributes->getString('token')));

        return new Passport(
            new UserBadge($email),
            new PasswordCredentials($request->getPayload()->getString('_password')),
            [new CsrfTokenBadge('authenticate', $request->getPayload()->getString('_csrf_token'))],
        );
    }

    /**
     * On to the controller: InvitationController::loggedIn().
     */
    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    /**
     * Back on the form, which says why, as form_login does.
     */
    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        $request->getSession()->set(SecurityRequestAttributes::AUTHENTICATION_ERROR, $exception);

        return new RedirectResponse($this->urlGenerator->generate('invitation_login', ['token' => $request->attributes->getString('token')]));
    }

    public function isInteractive(): bool
    {
        return true;
    }
}
