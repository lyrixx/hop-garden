<?php

namespace App\Garden;

use App\Garden\Security\GardenVoter;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\FinishRequestEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\RequestContext;

/**
 * The garden of the URL, /g/{garden}/...: found by its slug, for its members only (GARDEN_VIEW), else a 404, the same as
 * a garden that does not exist. It is then the current garden of the request (CurrentGarden), the entity in place of
 * its slug in the attributes (for the controllers and #[MapEntity]), the last garden of the session (LastGarden), and
 * the garden of the URLs the request generates without one: the links of a page stay in its garden. A page out of a
 * garden (the account, the wizard) links to the last one.
 *
 * After the firewall (8), which sends a visitor to the login, and RequestLocaleListener (7): the 404 is in their
 * language.
 */
#[AsEventListener(event: KernelEvents::REQUEST, method: 'enter', priority: 6)]
#[AsEventListener(event: KernelEvents::FINISH_REQUEST, method: 'leave')]
final readonly class GardenOfTheUrl
{
    /**
     * The route parameter, then the garden.
     */
    public const string ATTRIBUTE = 'garden';

    public function __construct(
        private GardenRepository $gardenRepository,
        private Security $security,
        private LastGarden $lastGarden,
        private RequestContext $requestContext,
    ) {
    }

    public function enter(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $slug = $request->attributes->get(self::ATTRIBUTE);
        if (\is_string($slug)) {
            $garden = $this->gardenRepository->findOneBy(['slug' => $slug]);
            if (null === $garden || !$this->security->isGranted(GardenVoter::VIEW, $garden)) {
                throw new NotFoundHttpException(\sprintf('No garden "%s" of the member.', $slug));
            }

            $request->attributes->set(self::ATTRIBUTE, $garden);
            $this->lastGarden->remember($garden);
        } else {
            $garden = $this->lastGarden->find();
        }

        if (null !== $garden) {
            $this->requestContext->setParameter(self::ATTRIBUTE, $garden->getSlug());
        }
    }

    /**
     * What runs after a request in the same process (the worker of the tests) never gets its garden. The next request
     * starts from the context of before (RequestContextResetter of the framework, a PHP worker of FrankenPHP too).
     */
    public function leave(FinishRequestEvent $event): void
    {
        if ($event->isMainRequest()) {
            $this->requestContext->setParameters(array_diff_key($this->requestContext->getParameters(), [self::ATTRIBUTE => true]));
        }
    }
}
