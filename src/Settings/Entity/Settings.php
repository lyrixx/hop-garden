<?php

namespace App\Settings\Entity;

use App\Locale\Model\SupportedLocale;
use App\Settings\SettingsRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * The settings of the application: one row. Its preferred locale is the language of the application, read through its
 * role (App\Locale\PreferredLocale): of a visitor who did not choose one, and of the reminder check, without recipient.
 * The members choose theirs in their account.
 */
#[ORM\Entity(repositoryClass: SettingsRepository::class)]
class Settings
{
    /**
     * Fixed, not generated as the ids of the other entities: a second row cannot be inserted.
     */
    public const string ID = '01a2058d-da55-7e7c-a921-eca72d7f9284';

    // Only Doctrine reads it
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id; // @phpstan-ignore property.onlyWritten

    /**
     * Null until chosen: the interface stays in the default language, and offers the one of the browser.
     */
    #[ORM\Column(nullable: true)]
    private ?SupportedLocale $preferredLocale = null;

    public function __construct()
    {
        $this->id = Uuid::fromString(self::ID);
    }

    public function choosePreferredLocale(SupportedLocale $locale): void
    {
        $this->preferredLocale = $locale;
    }

    public function getPreferredLocale(): ?SupportedLocale
    {
        return $this->preferredLocale;
    }
}
