<?php

namespace App\Tests\Unit\Locale;

use App\Locale\IntlFormatter;
use App\Tests\Utils\Translation\Translators;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

final class IntlFormatterTest extends TestCase
{
    #[TestWith(['en', 'Thursday 3 December', '3 December 2026', 'Thursday 3 December, 07:00'])]
    #[TestWith(['fr', 'jeudi 3 décembre', '3 décembre 2026', 'jeudi 3 décembre, 07:00'])]
    public function testDates(string $locale, string $dayAndMonth, string $longDate, string $dayAndTime): void
    {
        $formatter = self::formatter($locale);
        $date = new \DateTimeImmutable('2026-12-03 07:00', new \DateTimeZone('Europe/Paris'));

        self::assertSame($dayAndMonth, $formatter->dayAndMonth($date));
        self::assertSame($longDate, $formatter->longDate($date));
        self::assertSame($dayAndTime, $formatter->dayAndTime($date));
    }

    public function testADayIsTheOneOfItsOwnTimezone(): void
    {
        // Midnight in Paris is still the day before in UTC, the timezone of PHP
        $midnight = new \DateTimeImmutable('2026-12-03 00:00', new \DateTimeZone('Europe/Paris'));

        self::assertSame('jeudi 3 décembre', self::formatter('fr')->dayAndMonth($midnight));
    }

    #[TestWith(['en', 'June', 'Jun', 'J', 'July', 'Jul'])]
    #[TestWith(['fr', 'juin', 'juin', 'J', 'juillet', 'juil.'])]
    public function testMonths(string $locale, string $june, string $juneShort, string $juneInitial, string $july, string $julyShort): void
    {
        $formatter = self::formatter($locale);

        self::assertSame([$june, $juneShort, $juneInitial], [$formatter->monthName(6), $formatter->monthAbbreviation(6), $formatter->monthInitial(6)]);
        self::assertSame([$july, $julyShort], [$formatter->monthName(7), $formatter->monthAbbreviation(7)], 'Never cut by hand: juin and juillet would both be "jui".');
    }

    #[TestWith(['en', 'March', 'March and November', 'February, March and November'])]
    #[TestWith(['fr', 'mars', 'mars et novembre', 'février, mars et novembre'])]
    public function testMonthLists(string $locale, string $one, string $two, string $three): void
    {
        $formatter = self::formatter($locale);

        self::assertSame([$one, $two, $three], [$formatter->monthList([3]), $formatter->monthList([3, 11]), $formatter->monthList([2, 3, 11])]);
    }

    public function testAnInvalidMonthIsABug(): void
    {
        $this->expectException(\LogicException::class);

        self::formatter('en')->monthName(13);
    }

    #[TestWith(['en', -12.4, '-12.4 °C'])]
    #[TestWith(['en', -8.0, '-8.0 °C'])]
    #[TestWith(['fr', -12.4, "-12,4\u{a0}°C"])]
    #[TestWith(['fr', 0.04, "0,0\u{a0}°C"])]
    public function testTemperatures(string $locale, float $celsius, string $expected): void
    {
        self::assertSame($expected, self::formatter($locale)->temperature($celsius));
    }

    private static function formatter(string $locale): IntlFormatter
    {
        return new IntlFormatter(Translators::for($locale));
    }
}
