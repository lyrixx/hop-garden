<?php

namespace App\Garden\Recommendation;

use App\DataSource\OpenMeteo\Model\FrostForecast;
use App\Garden\Entity\Planting;
use App\Garden\Model\CareTask;
use App\Garden\Recommendation\Model\Recommendation;
use App\Garden\Recommendation\Model\RecommendationLevel;
use App\Garden\Recommendation\Model\RecommendationSubject;
use App\Plant\CareSheetPresenter;
use App\Plant\Entity\CareSheet;
use App\Plant\Model\Exposure;
use App\Plant\Model\Watering;
use App\Reminder\FrostRiskEvaluator;
use App\Reminder\Model\ReminderType;

/**
 * Compares what the user sees in the garden with the care sheet of the species, with fixed rules. The exposure is
 * the one of the space the plant grows in. Soil, diseases and neighbours have no field in the care sheet: they are
 * left to the advice written by the agent. A fact the user did not give yet is asked for, never guessed.
 */
final readonly class RecommendationEngine
{
    private const string DATE_FORMAT = 'j F Y';
    private const string FORECAST_DATE_FORMAT = 'l j F';

    /**
     * Frosts happen from October to April, in the temperate Western European climate the care data assumes.
     */
    private const int COLD_SEASON_FIRST_MONTH = 10;
    private const int COLD_SEASON_LAST_MONTH = 4;

    /**
     * A frost protection set up from September on counts for the coming cold season.
     */
    private const int FROST_PROTECTION_FIRST_MONTH = 9;

    public function __construct(
        private FrostRiskEvaluator $frostRiskEvaluator,
    ) {
    }

    /**
     * @param ?FrostForecast $forecast null when unknown: the frost advice then relies on the hardiness only
     *
     * @return list<Recommendation> the most pressing first
     */
    public function recommend(Planting $planting, CareSheet $careSheet, \DateTimeImmutable $today, ?FrostForecast $forecast): array
    {
        $recommendations = [
            $this->exposure($planting, $careSheet),
            $this->watering($planting, $careSheet),
            $this->frost($planting, $careSheet, $today, $forecast),
            $this->pruning($planting, $careSheet, $today),
        ];

        usort($recommendations, static fn (Recommendation $a, Recommendation $b): int => $a->level->priority() <=> $b->level->priority());

        return $recommendations;
    }

    private function exposure(Planting $planting, CareSheet $careSheet): Recommendation
    {
        $preferred = $careSheet->getExposure();
        $space = $planting->getSpace();
        if (null === $space) {
            return new Recommendation(RecommendationLevel::Info, RecommendationSubject::Exposure, \sprintf(
                'Tell us where it grows, with "Edit garden info": its care sheet asks for %s.',
                mb_strtolower($preferred->label()),
            ));
        }

        $actual = $space->getExposure();
        if (null === $actual) {
            return new Recommendation(RecommendationLevel::Info, RecommendationSubject::Exposure, \sprintf(
                'Tell us the exposure of %s, in "My spaces": its care sheet asks for %s.',
                $space->getName(),
                mb_strtolower($preferred->label()),
            ));
        }

        return match (abs(self::sunlight($preferred) - self::sunlight($actual))) {
            0 => new Recommendation(RecommendationLevel::Ok, RecommendationSubject::Exposure, \sprintf(
                '%s suits it.',
                $actual->label(),
            )),
            1 => new Recommendation(RecommendationLevel::Info, RecommendationSubject::Exposure, \sprintf(
                'It prefers %1$s and gets %2$s here: it should cope, but would do better in %1$s.',
                mb_strtolower($preferred->label()),
                mb_strtolower($actual->label()),
            )),
            default => new Recommendation(RecommendationLevel::Warning, RecommendationSubject::Exposure, \sprintf(
                'It needs %1$s and gets %2$s here: move it to a spot in %1$s, in autumn or early spring.',
                mb_strtolower($preferred->label()),
                mb_strtolower($actual->label()),
            )),
        };
    }

    private function watering(Planting $planting, CareSheet $careSheet): Recommendation
    {
        $exposure = $planting->getSpace()?->getExposure();

        $message = match ($careSheet->getWatering()) {
            Watering::High => Exposure::Sun === $exposure
                ? 'It needs plenty of water and gets full sun here: water it often in summer, and mulch the soil to keep it moist.'
                : 'It needs plenty of water: keep the soil moist, especially in summer.',
            Watering::Medium => 'Water it during dry spells.',
            Watering::Low => Exposure::Shade === $exposure
                ? 'It needs little water, and the shade keeps the soil moist: water it only during long droughts.'
                : 'It needs little water: water it only during long droughts, and never leave it in soggy soil.',
        };

        $lastWatered = $planting->lastDone(CareTask::Watering);
        if (null !== $lastWatered) {
            $message .= \sprintf(' Last watered on %s.', $lastWatered->format(self::DATE_FORMAT));
        }

        return new Recommendation(RecommendationLevel::Info, RecommendationSubject::Watering, $message);
    }

    private function frost(Planting $planting, CareSheet $careSheet, \DateTimeImmutable $today, ?FrostForecast $forecast): Recommendation
    {
        $protectedOn = $this->frostProtectionOfTheSeason($planting, $today);
        $hardiness = $careSheet->getMinTemperatureC();
        $risk = null !== $forecast ? $this->frostRiskEvaluator->evaluate($careSheet, $forecast) : null;

        if (null !== $risk) {
            $coldest = $risk->coldestDay();
            $expected = $coldest === $risk->firstDay()
                ? \sprintf('Frost expected on %s (%s °C)', $coldest->date->format(self::FORECAST_DATE_FORMAT), number_format($coldest->minTemperatureC, 1))
                : \sprintf(
                    'Frost expected from %s, down to %s °C on %s',
                    $risk->firstDay()->date->format(self::FORECAST_DATE_FORMAT),
                    number_format($coldest->minTemperatureC, 1),
                    $coldest->date->format(self::FORECAST_DATE_FORMAT),
                );

            if (null !== $protectedOn) {
                return new Recommendation(RecommendationLevel::Info, RecommendationSubject::Frost, \sprintf(
                    '%s. Sheltered on %s: check that the protection holds.',
                    $expected,
                    $protectedOn->format(self::DATE_FORMAT),
                ));
            }

            return new Recommendation(RecommendationLevel::Warning, RecommendationSubject::Frost, $careSheet->isFrostSensitive()
                ? \sprintf('%s. It is frost sensitive: bring it indoors or shelter it.', $expected)
                : \sprintf('%s, colder than its hardiness (%d °C): cover it with a winter fleece.', $expected, $hardiness),
            );
        }

        if (!$careSheet->isFrostSensitive()) {
            return new Recommendation(RecommendationLevel::Ok, RecommendationSubject::Frost, \sprintf(
                'Hardy down to %d °C%s.',
                $hardiness,
                null !== $forecast ? ': the forecast stays above' : '',
            ));
        }

        if (!self::isColdSeason($today)) {
            return new Recommendation(RecommendationLevel::Info, RecommendationSubject::Frost, \sprintf(
                'Frost sensitive (hardy down to %d °C): bring it indoors or shelter it before the first autumn frost.',
                $hardiness,
            ));
        }

        if (null !== $protectedOn) {
            return new Recommendation(RecommendationLevel::Ok, RecommendationSubject::Frost, \sprintf(
                'Sheltered from frost on %s.',
                $protectedOn->format(self::DATE_FORMAT),
            ));
        }

        return new Recommendation(RecommendationLevel::Info, RecommendationSubject::Frost, \sprintf(
            'Frost sensitive (hardy down to %d °C), and no frost protection recorded this winter: bring it indoors or shelter it.',
            $hardiness,
        ));
    }

    private function pruning(Planting $planting, CareSheet $careSheet, \DateTimeImmutable $today): Recommendation
    {
        $months = ReminderType::Pruning->monthsIn($careSheet);
        $advice = trim($careSheet->getPruningAdvice());
        $lastPruned = $planting->lastDone(CareTask::Pruning);

        if ([] === $months) {
            return new Recommendation(RecommendationLevel::Ok, RecommendationSubject::Pruning, trim('No regular pruning in its care sheet. ' . $advice));
        }

        $when = self::monthList($months);
        $lastPrunedOn = null !== $lastPruned ? \sprintf(' Last pruned on %s.', $lastPruned->format(self::DATE_FORMAT)) : '';

        $periodStart = self::pruningPeriodStart($months, $today);
        if (null === $periodStart) {
            return new Recommendation(RecommendationLevel::Info, RecommendationSubject::Pruning, trim(\sprintf('Prune it in %s. %s', $when, $advice)) . $lastPrunedOn);
        }

        if (null !== $lastPruned && $lastPruned->format('Y-m-d') >= $periodStart->format('Y-m-d')) {
            return new Recommendation(RecommendationLevel::Ok, RecommendationSubject::Pruning, \sprintf(
                'Pruned on %s: done for this season. Next time in %s.',
                $lastPruned->format(self::DATE_FORMAT),
                $when,
            ));
        }

        return new Recommendation(RecommendationLevel::Action, RecommendationSubject::Pruning, trim(\sprintf('Time to prune it (%s). %s', $when, $advice)) . $lastPrunedOn);
    }

    /**
     * When a frost protection was set up for the current cold season, or null: last winter's does not count.
     */
    private function frostProtectionOfTheSeason(Planting $planting, \DateTimeImmutable $today): ?\DateTimeImmutable
    {
        $protectedOn = $planting->lastDone(CareTask::FrostProtection);
        if (null === $protectedOn) {
            return null;
        }

        $year = (int) $today->format('Y');
        // Until April, the cold season started the previous autumn
        $seasonYear = (int) $today->format('n') <= self::COLD_SEASON_LAST_MONTH ? $year - 1 : $year;
        $seasonStart = \sprintf('%d-%02d-01', $seasonYear, self::FROST_PROTECTION_FIRST_MONTH);

        return $protectedOn->format('Y-m-d') >= $seasonStart ? $protectedOn : null;
    }

    /**
     * The first day of the pruning months in a row that holds today (December and January follow each other), or
     * null when today is not in a pruning month.
     *
     * @param list<int> $months
     */
    private static function pruningPeriodStart(array $months, \DateTimeImmutable $today): ?\DateTimeImmutable
    {
        if (!\in_array((int) $today->format('n'), $months, true)) {
            return null;
        }

        $start = $today->modify('first day of this month')->setTime(0, 0);
        // A care sheet may list every month: never go back more than a year
        for ($i = 1; $i < 12; ++$i) {
            $previous = $start->modify('first day of previous month');
            if (!\in_array((int) $previous->format('n'), $months, true)) {
                break;
            }
            $start = $previous;
        }

        return $start;
    }

    private static function isColdSeason(\DateTimeImmutable $today): bool
    {
        $month = (int) $today->format('n');

        return $month >= self::COLD_SEASON_FIRST_MONTH || $month <= self::COLD_SEASON_LAST_MONTH;
    }

    /**
     * From the sunniest (0) to the shadiest.
     */
    private static function sunlight(Exposure $exposure): int
    {
        return match ($exposure) {
            Exposure::Sun => 0,
            Exposure::PartShade => 1,
            Exposure::Shade => 2,
        };
    }

    /**
     * @param non-empty-list<int> $months
     */
    private static function monthList(array $months): string
    {
        $names = array_map(CareSheetPresenter::monthName(...), $months);
        $last = array_pop($names);

        return [] === $names ? $last : implode(', ', $names) . ' and ' . $last;
    }
}
