<?php

namespace App\Locale;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * The locale of every request is the preferred one, never the URL's nor the browser's: the browser only gets it
 * offered (templates/components/LocaleBanner.html.twig). Before the LocaleAwareListener of Symfony (15), which passes
 * it on to the translator and the other locale-aware services.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 20)]
final readonly class RequestLocaleListener
{
    public function __construct(
        private PreferredLocale $preferredLocale,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $event->getRequest()->setLocale($this->preferredLocale->get()->value);
    }
}
