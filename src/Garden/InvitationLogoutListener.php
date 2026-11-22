<?php

namespace App\Garden;

use App\Controller\InvitationController;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Event\LogoutEvent;

/**
 * Logged in with another account than the one invited, a member logs out from the page of the invitation: back on it,
 * as a visitor, to log in with the right one or create it. Before the redirection to "/" of the logout (priority 64).
 */
#[AsEventListener(event: LogoutEvent::class, priority: 65, dispatcher: 'security.event_dispatcher.main')]
final readonly class InvitationLogoutListener
{
    /**
     * The field of the logout form of templates/invitation/show.html.twig.
     */
    public const string FIELD = 'invitation_token';

    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function __invoke(LogoutEvent $event): void
    {
        $token = $event->getRequest()->request->getString(self::FIELD);
        if (1 !== preg_match('{^' . InvitationController::TOKEN_REQUIREMENT . '$}', $token)) {
            return;
        }

        $event->setResponse(new RedirectResponse($this->urlGenerator->generate('invitation', ['token' => $token])));
    }
}
