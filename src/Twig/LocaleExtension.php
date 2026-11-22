<?php

namespace App\Twig;

use App\Locale\IntlFormatter;
use Twig\Attribute\AsTwigFilter;
use Twig\Attribute\AsTwigFunction;

/**
 * The dates, the months and the temperatures of the templates, in the language of the page: see IntlFormatter.
 */
final readonly class LocaleExtension
{
    public function __construct(
        private IntlFormatter $formatter,
    ) {
    }

    #[AsTwigFilter('day_and_month')]
    public function dayAndMonth(\DateTimeImmutable $date): string
    {
        return $this->formatter->dayAndMonth($date);
    }

    #[AsTwigFilter('long_date')]
    public function longDate(\DateTimeImmutable $date): string
    {
        return $this->formatter->longDate($date);
    }

    #[AsTwigFilter('day_and_time')]
    public function dayAndTime(\DateTimeImmutable $date): string
    {
        return $this->formatter->dayAndTime($date);
    }

    #[AsTwigFilter('temperature')]
    public function temperature(float $celsius): string
    {
        return $this->formatter->temperature($celsius);
    }

    #[AsTwigFunction('month_abbr')]
    public function monthAbbreviation(int $month): string
    {
        return $this->formatter->monthAbbreviation($month);
    }

    #[AsTwigFunction('month_initial')]
    public function monthInitial(int $month): string
    {
        return $this->formatter->monthInitial($month);
    }
}
