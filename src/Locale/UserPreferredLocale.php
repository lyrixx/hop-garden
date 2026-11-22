<?php

namespace App\Locale;

use App\Account\Entity\User;
use App\Locale\Model\SupportedLocale;
use App\Settings\SettingsRepository;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * The language of the member logged in, in their account; of a visitor, in a cookie; else the one of the application,
 * in its settings: what a visitor gets until they choose, and the reminder check of the scheduler, which has neither.
 */
#[AsEventListener(event: KernelEvents::RESPONSE, method: 'writeCookie')]
final readonly class UserPreferredLocale implements PreferredLocale
{
    public const string COOKIE = 'locale';

    // The choice of the current request, for its response
    private const string CHOSEN = '_preferred_locale';

    public function __construct(
        private Security $security,
        private RequestStack $requestStack,
        private SettingsRepository $settingsRepository,
        private ClockInterface $clock,
    ) {
    }

    public function get(): SupportedLocale
    {
        return $this->user()?->getPreferredLocale()
            ?? $this->visitorLocale()
            ?? $this->settingsRepository->findTheSettings()?->getPreferredLocale()
            ?? SupportedLocale::DEFAULT;
    }

    /**
     * The choice of a member goes to the cookie too: the pages they see logged out (the login...) keep it.
     */
    public function set(SupportedLocale $locale): void
    {
        $this->user()?->choosePreferredLocale($locale);
        $this->requestStack->getMainRequest()?->attributes->set(self::CHOSEN, $locale);
    }

    public function isChosen(): bool
    {
        return null !== ($this->user()?->getPreferredLocale() ?? $this->visitorLocale());
    }

    public function writeCookie(ResponseEvent $event): void
    {
        $locale = $event->getRequest()->attributes->get(self::CHOSEN);
        if (!$event->isMainRequest() || !$locale instanceof SupportedLocale) {
            return;
        }

        $event->getResponse()->headers->setCookie(Cookie::create(self::COOKIE, $locale->value, $this->clock->now()->modify('+1 year')));
    }

    private function user(): ?User
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $user : null;
    }

    private function visitorLocale(): ?SupportedLocale
    {
        $request = $this->requestStack->getMainRequest();
        if (null === $request) {
            return null;
        }

        $chosen = $request->attributes->get(self::CHOSEN);

        return $chosen instanceof SupportedLocale ? $chosen : SupportedLocale::tryFrom($request->cookies->getString(self::COOKIE));
    }
}
