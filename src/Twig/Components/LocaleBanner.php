<?php

namespace App\Twig\Components;

use App\Locale\Model\SupportedLocale;
use App\Locale\PreferredLocale;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * Offers the language of the browser (Accept-Language) until a language is chosen: the page stays in the default one,
 * and nothing changes without a tap.
 */
#[AsTwigComponent]
final readonly class LocaleBanner
{
    public function __construct(
        private PreferredLocale $preferredLocale,
        private RequestStack $requestStack,
    ) {
    }

    /**
     * Null once chosen, or when the browser prefers the current language, or none we have.
     */
    public function getOffered(): ?SupportedLocale
    {
        $request = $this->requestStack->getMainRequest();
        if (null === $request || $this->preferredLocale->isChosen()) {
            return null;
        }

        // The first of ours when the browser asks for none of them
        $offered = SupportedLocale::tryFrom((string) $request->getPreferredLanguage(SupportedLocale::values()));

        return $offered !== $this->preferredLocale->get() ? $offered : null;
    }

    public function getCurrent(): SupportedLocale
    {
        return $this->preferredLocale->get();
    }
}
