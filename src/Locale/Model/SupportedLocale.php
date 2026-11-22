<?php

namespace App\Locale\Model;

/**
 * The languages of the interface. Keep in sync with framework.enabled_locales and framework.default_locale
 * (config/packages/translation.yaml), and with the files of translations/.
 */
enum SupportedLocale: string
{
    case English = 'en';
    case French = 'fr';

    public const self DEFAULT = self::English;

    /**
     * Its name in its own language, the same whatever the language of the page: the way back for whoever does not read
     * the current one.
     */
    public function nativeName(): string
    {
        return match ($this) {
            self::English => 'English',
            self::French => 'Français',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $locale): string => $locale->value, self::cases());
    }
}
