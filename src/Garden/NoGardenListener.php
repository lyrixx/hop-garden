<?php

namespace App\Garden;

use App\Garden\Exception\NoCurrentGardenException;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * A page of the garden asked for the current garden of a member who has none yet: the wizard makes their first one.
 * The pages without garden (the account, the wizard itself) never ask.
 */
#[AsEventListener(event: KernelEvents::EXCEPTION)]
final readonly class NoGardenListener
{
    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        // Twig wraps what a template throws
        for ($exception = $event->getThrowable(); null !== $exception; $exception = $exception->getPrevious()) {
            if ($exception instanceof NoCurrentGardenException) {
                break;
            }
        }

        if (null === $exception || !$event->isMainRequest() || !$event->getRequest()->hasSession()) {
            return;
        }

        $event->setResponse(new RedirectResponse($this->urlGenerator->generate('garden_create'), Response::HTTP_SEE_OTHER));
    }
}
