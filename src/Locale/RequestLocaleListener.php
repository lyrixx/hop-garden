<?php

namespace App\Locale;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Translation\LocaleSwitcher;

/**
 * The locale of every request is the preferred one, never the URL's nor the browser's: the browser only gets it
 * offered (templates/components/LocaleBanner.html.twig). After the firewall (8), which knows the member: so after the
 * LocaleAwareListener of Symfony (15) too, and the LocaleSwitcher passes it on to the translator and the other
 * locale-aware services. A stateless route (#[Route(stateless: true)], the probe /healthz) speaks to no one: it keeps
 * the default locale, without the query of the settings.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 7)]
final readonly class RequestLocaleListener
{
    public function __construct(
        private PreferredLocale $preferredLocale,
        private LocaleSwitcher $localeSwitcher,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || $request->attributes->getBoolean('_stateless')) {
            return;
        }

        $locale = $this->preferredLocale->get()->value;
        $request->setLocale($locale);
        $this->localeSwitcher->setLocale($locale);
    }
}
