<?php

namespace App\Monitoring;

use App\Account\Entity\User;
use Sentry\State\HubInterface;
use Sentry\State\Scope;
use Sentry\UserDataBag;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * The member logged in of the Sentry issues, by their id only ("user:<uuid>"): no email, no display name, no IP
 * (sentry.yaml, data_collection). Sentry counts the members an issue hits. Right after the firewall (8), as
 * RequestLocaleListener, which reads the member too: before GardenOfTheUrl (6) and the controller, which may fail. The
 * stateless firewall of the agent (mcp_agent) logs in whoever sent the message, from the token of the run, so an error
 * of an MCP tool the agent calls is theirs too. A stateless route (the probe /healthz) speaks to no one, and reads no
 * session.
 */
#[AsEventListener(priority: 7)]
final readonly class SentryUserListener
{
    public function __construct(
        private HubInterface $hub,
        private TokenStorageInterface $tokenStorage,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || $event->getRequest()->attributes->getBoolean('_stateless')) {
            return;
        }

        $user = $this->tokenStorage->getToken()?->getUser();
        if (!$user instanceof User) {
            return;
        }

        $id = 'user:' . $user->getId()->toRfc4122();
        $this->hub->configureScope(static function (Scope $scope) use ($id): void {
            $scope->setUser(new UserDataBag($id));
        });
    }
}
