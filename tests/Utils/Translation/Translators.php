<?php

namespace App\Tests\Utils\Translation;

use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;

/**
 * The translator of the application, without a kernel: the catalogs of translations/, for the unit tests.
 */
final class Translators
{
    public static function for(string $locale): Translator
    {
        $translator = new Translator($locale);
        $translator->addLoader('yaml', new YamlFileLoader());
        foreach (self::catalogs() as $file => [$domain, $catalogLocale]) {
            $translator->addResource('yaml', $file, $catalogLocale, $domain);
        }

        return $translator;
    }

    /**
     * @return array<string, array{string, string}> the domain ("messages+intl-icu") and the locale of each file
     */
    public static function catalogs(): array
    {
        $catalogs = [];
        foreach (glob(\dirname(__DIR__, 3) . '/translations/*.yaml') ?: [] as $file) {
            [$domain, $locale] = explode('.', basename($file, '.yaml'), 2);
            $catalogs[$file] = [$domain, $locale];
        }

        return $catalogs;
    }
}
