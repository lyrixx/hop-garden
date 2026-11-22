<?php

namespace App\Tests\Integration\Translation;

use App\Locale\Model\SupportedLocale;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Every message the templates and the code use exists in every language, and every catalog is valid ICU.
 */
final class TranslationCommandsTest extends KernelTestCase
{
    #[DataProvider('provideNoMessageIsMissingCases')]
    public function testNoMessageIsMissing(string $locale): void
    {
        $tester = $this->command('debug:translation');
        $tester->execute(['locale' => $locale, '--only-missing' => true]);

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideNoMessageIsMissingCases(): iterable
    {
        foreach (SupportedLocale::values() as $locale) {
            yield $locale => [$locale];
        }
    }

    public function testTheCatalogsAreValid(): void
    {
        $tester = $this->command('lint:translations');
        $tester->execute([]);

        $tester->assertCommandIsSuccessful($tester->getDisplay());
    }

    private function command(string $name): CommandTester
    {
        return new CommandTester(new Application(self::bootKernel())->find($name));
    }
}
