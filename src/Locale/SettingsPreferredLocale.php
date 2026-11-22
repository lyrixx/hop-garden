<?php

namespace App\Locale;

use App\Locale\Model\SupportedLocale;
use App\Settings\Entity\Settings;
use App\Settings\SettingsRepository;

/**
 * The preferred locale of the application, in its settings: Hop Garden has no accounts yet.
 */
final readonly class SettingsPreferredLocale implements PreferredLocale
{
    public function __construct(
        private SettingsRepository $settingsRepository,
    ) {
    }

    public function get(): SupportedLocale
    {
        return $this->settingsRepository->findTheSettings()?->getPreferredLocale() ?? SupportedLocale::DEFAULT;
    }

    public function set(SupportedLocale $locale): void
    {
        $settings = $this->settingsRepository->findTheSettings();
        if (null === $settings) {
            $settings = new Settings();
            $this->settingsRepository->add($settings);
        }

        $settings->choosePreferredLocale($locale);
    }

    public function isChosen(): bool
    {
        return null !== $this->settingsRepository->findTheSettings()?->getPreferredLocale();
    }
}
