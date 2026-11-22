<?php

namespace App\Settings\Entity;

use App\Locale\Model\SupportedLocale;
use App\Settings\SettingsRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * The settings of the application: one row, created by the first choice. Hop Garden has no accounts yet; read them
 * through their role (App\Locale\PreferredLocale), so that they can move to the user later.
 */
#[ORM\Entity(repositoryClass: SettingsRepository::class)]
class Settings
{
    /**
     * Fixed: a second row cannot be inserted.
     */
    public const int ID = 1;

    // Only Doctrine reads it
    #[ORM\Id]
    #[ORM\Column]
    private int $id = self::ID; // @phpstan-ignore property.onlyWritten

    /**
     * Null until chosen: the interface stays in the default language, and offers the one of the browser.
     */
    #[ORM\Column(nullable: true)]
    private ?SupportedLocale $preferredLocale = null;

    public function choosePreferredLocale(SupportedLocale $locale): void
    {
        $this->preferredLocale = $locale;
    }

    public function getPreferredLocale(): ?SupportedLocale
    {
        return $this->preferredLocale;
    }
}
