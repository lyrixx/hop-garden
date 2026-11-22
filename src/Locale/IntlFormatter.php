<?php

namespace App\Locale;

use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Dates, months and temperatures in the language of the translator: the request's, or the one the worker switched to.
 * Patterns rather than the styles of Intl: "Thursday 3 December" reads well in both languages, and the English one stays
 * as it was.
 */
final readonly class IntlFormatter
{
    public function __construct(
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * "Thursday 3 December", "jeudi 3 décembre": a day of the forecast or of a reminder.
     */
    public function dayAndMonth(\DateTimeImmutable $date): string
    {
        return $this->format($date, 'EEEE d MMMM');
    }

    /**
     * "3 December 2026", "3 décembre 2026".
     */
    public function longDate(\DateTimeImmutable $date): string
    {
        return $this->format($date, 'd MMMM y');
    }

    /**
     * "Thursday 3 December, 07:00", "jeudi 3 décembre, 07:00".
     */
    public function dayAndTime(\DateTimeImmutable $date): string
    {
        return $this->format($date, 'EEEE d MMMM, HH:mm');
    }

    /**
     * "March", "mars": standalone, as in a list or a label.
     */
    public function monthName(int $month): string
    {
        return $this->format(self::month($month), 'LLLL');
    }

    /**
     * "Mar", "mars"; "Jul", "juil.": never cut by hand, juin and juillet would both be "jui".
     */
    public function monthAbbreviation(int $month): string
    {
        return $this->format(self::month($month), 'LLL');
    }

    /**
     * "M": the ruler of the months of a care sheet.
     */
    public function monthInitial(int $month): string
    {
        return $this->format(self::month($month), 'LLLLL');
    }

    /**
     * "March and November", "mars et novembre".
     *
     * @param non-empty-list<int> $months
     */
    public function monthList(array $months): string
    {
        $names = array_map($this->monthName(...), $months);
        $last = array_pop($names);

        return [] === $names ? $last : $this->translator->trans('list.and', ['items' => implode(', ', $names), 'last' => $last]);
    }

    /**
     * "-12.4 °C", "-12,4 °C": a temperature of the forecast, to the tenth.
     */
    public function temperature(float $celsius): string
    {
        $formatter = new \NumberFormatter($this->translator->getLocale(), \NumberFormatter::DECIMAL);
        $formatter->setAttribute(\NumberFormatter::MIN_FRACTION_DIGITS, 1);
        $formatter->setAttribute(\NumberFormatter::MAX_FRACTION_DIGITS, 1);

        return $this->translator->trans('unit.celsius', ['temperature' => $formatter->format($celsius)]);
    }

    /**
     * In the timezone of the date itself: a day of the forecast starts at midnight in the one of the garden, which is
     * not the one of PHP.
     */
    private function format(\DateTimeImmutable $date, string $pattern): string
    {
        $formatter = new \IntlDateFormatter($this->translator->getLocale(), \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, $date->getTimezone(), \IntlDateFormatter::GREGORIAN, $pattern);
        $formatted = $formatter->format($date);
        if (false === $formatted) {
            throw new \LogicException(\sprintf('Intl cannot format a date with "%s": %s.', $pattern, $formatter->getErrorMessage()));
        }

        return $formatted;
    }

    private static function month(int $month): \DateTimeImmutable
    {
        if ($month < 1 || $month > 12) {
            throw new \LogicException(\sprintf('Invalid month %d.', $month));
        }

        // Mid-month at noon, in UTC: the same month in any timezone
        return new \DateTimeImmutable(\sprintf('2000-%02d-15 12:00', $month), new \DateTimeZone('UTC'));
    }
}
