<?php

namespace App\Tests\Unit\Translation;

use App\Locale\Model\SupportedLocale;
use App\Tests\Utils\Translation\Translators;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Every message of translations/ exists in every language of the interface.
 */
final class CatalogsTest extends TestCase
{
    public function testEveryLanguageHasTheSameKeys(): void
    {
        $keys = [];
        foreach (Translators::catalogs() as $file => [$domain, $locale]) {
            $keys[$domain][$locale] = self::keys(Yaml::parseFile($file));
        }

        self::assertSame(['messages+intl-icu', 'security', 'validators'], array_keys($keys));
        foreach ($keys as $domain => $byLocale) {
            self::assertSame(SupportedLocale::values(), array_keys($byLocale), $domain);
            foreach ($byLocale as $locale => $localeKeys) {
                self::assertSame($byLocale[SupportedLocale::DEFAULT->value], $localeKeys, \sprintf('The keys of %s in "%s".', $domain, $locale));
            }
        }
    }

    public function testAFrenchApostropheNeverQuotesAPlaceholder(): void
    {
        $messages = Translators::for(SupportedLocale::French->value)->getCatalogue()->all('messages');

        self::assertNotEmpty($messages);
        foreach ($messages as $key => $message) {
            self::assertStringNotContainsString("'", $message, \sprintf('"%s": in ICU, \' before a brace prints it as is, write ’.', $key));
        }
    }

    /**
     * @param array<mixed> $catalog
     *
     * @return list<string> sorted
     */
    private static function keys(array $catalog, string $prefix = ''): array
    {
        $keys = [];
        foreach ($catalog as $key => $value) {
            $keys = [...$keys, ...(\is_array($value) ? self::keys($value, $prefix . $key . '.') : [$prefix . $key])];
        }
        sort($keys);

        return $keys;
    }
}
