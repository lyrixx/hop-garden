<?php

namespace App\Garden\Recommendation;

use App\DataSource\OpenMeteo\Model\FrostForecast;
use App\Garden\Entity\Planting;
use App\Garden\Model\CareTask;
use App\Garden\Recommendation\Model\Recommendation;
use App\Garden\Recommendation\Model\RecommendationLevel;
use App\Garden\Recommendation\Model\RecommendationSubject;
use App\Locale\IntlFormatter;
use App\Plant\Entity\CareSheet;
use App\Plant\Model\Exposure;
use App\Plant\Model\Watering;
use App\Reminder\FrostRiskEvaluator;
use App\Reminder\Model\ReminderType;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Compares what the user sees in the garden with the care sheet of the species, with fixed rules. The exposure is
 * the one of the space the plant grows in. Soil, diseases and neighbours have no field in the care sheet: they are
 * left to the advice written by the agent. A fact the user did not give yet is asked for, never guessed. In the
 * language of the translator.
 */
final readonly class RecommendationEngine
{
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
        private TranslatorInterface $translator,
        private IntlFormatter $formatter,
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
        // The exposures as their values: a language needs its own articles and prepositions for each
        if (null === $space) {
            return new Recommendation(RecommendationLevel::Info, RecommendationSubject::Exposure, $this->translator->trans('recommendation.exposure.no_space', [
                'button' => $this->translator->trans('planting.edit.breadcrumb'),
                'preferred' => $preferred->value,
            ]));
        }

        $actual = $space->getExposure();
        if (null === $actual) {
            return new Recommendation(RecommendationLevel::Info, RecommendationSubject::Exposure, $this->translator->trans('recommendation.exposure.unknown', [
                'space' => $space->getName(),
                'page' => $this->translator->trans('nav.spaces'),
                'preferred' => $preferred->value,
            ]));
        }

        $exposures = ['preferred' => $preferred->value, 'actual' => $actual->value];

        return match (abs(self::sunlight($preferred) - self::sunlight($actual))) {
            0 => new Recommendation(RecommendationLevel::Ok, RecommendationSubject::Exposure, $this->translator->trans('recommendation.exposure.suits', $exposures)),
            1 => new Recommendation(RecommendationLevel::Info, RecommendationSubject::Exposure, $this->translator->trans('recommendation.exposure.close', $exposures)),
            default => new Recommendation(RecommendationLevel::Warning, RecommendationSubject::Exposure, $this->translator->trans('recommendation.exposure.far', $exposures)),
        };
    }

    private function watering(Planting $planting, CareSheet $careSheet): Recommendation
    {
        $exposure = $planting->getSpace()?->getExposure();

        $message = $this->translator->trans(match ($careSheet->getWatering()) {
            Watering::High => Exposure::Sun === $exposure ? 'recommendation.watering.high_in_sun' : 'recommendation.watering.high',
            Watering::Medium => 'recommendation.watering.medium',
            Watering::Low => Exposure::Shade === $exposure ? 'recommendation.watering.low_in_shade' : 'recommendation.watering.low',
        });

        $lastWatered = $planting->lastDone(CareTask::Watering);
        if (null !== $lastWatered) {
            $message .= ' ' . $this->translator->trans('recommendation.watering.last', ['date' => $this->formatter->longDate($lastWatered)]);
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
                ? $this->translator->trans('recommendation.frost.expected_on', [
                    'date' => $this->formatter->dayAndMonth($coldest->date),
                    'temperature' => $this->formatter->temperature($coldest->minTemperatureC),
                ])
                : $this->translator->trans('recommendation.frost.expected_from', [
                    'first' => $this->formatter->dayAndMonth($risk->firstDay()->date),
                    'temperature' => $this->formatter->temperature($coldest->minTemperatureC),
                    'coldest' => $this->formatter->dayAndMonth($coldest->date),
                ]);

            if (null !== $protectedOn) {
                return new Recommendation(RecommendationLevel::Info, RecommendationSubject::Frost, $this->translator->trans('recommendation.frost.sheltered_check', [
                    'expected' => $expected,
                    'date' => $this->formatter->longDate($protectedOn),
                ]));
            }

            return new Recommendation(RecommendationLevel::Warning, RecommendationSubject::Frost, $careSheet->isFrostSensitive()
                ? $this->translator->trans('recommendation.frost.bring_indoors', ['expected' => $expected])
                : $this->translator->trans('recommendation.frost.cover', ['expected' => $expected, 'hardiness' => $hardiness]),
            );
        }

        if (!$careSheet->isFrostSensitive()) {
            return new Recommendation(RecommendationLevel::Ok, RecommendationSubject::Frost, $this->translator->trans(
                null !== $forecast ? 'recommendation.frost.hardy_above_forecast' : 'recommendation.frost.hardy',
                ['hardiness' => $hardiness],
            ));
        }

        if (!self::isColdSeason($today)) {
            return new Recommendation(RecommendationLevel::Info, RecommendationSubject::Frost, $this->translator->trans('recommendation.frost.sensitive_before_autumn', ['hardiness' => $hardiness]));
        }

        if (null !== $protectedOn) {
            return new Recommendation(RecommendationLevel::Ok, RecommendationSubject::Frost, $this->translator->trans('recommendation.frost.sheltered', ['date' => $this->formatter->longDate($protectedOn)]));
        }

        return new Recommendation(RecommendationLevel::Info, RecommendationSubject::Frost, $this->translator->trans('recommendation.frost.sensitive_unprotected', ['hardiness' => $hardiness]));
    }

    private function pruning(Planting $planting, CareSheet $careSheet, \DateTimeImmutable $today): Recommendation
    {
        $months = ReminderType::Pruning->monthsIn($careSheet);
        $advice = trim($careSheet->getPruningAdvice());
        $lastPruned = $planting->lastDone(CareTask::Pruning);

        if ([] === $months) {
            return new Recommendation(RecommendationLevel::Ok, RecommendationSubject::Pruning, trim($this->translator->trans('recommendation.pruning.none') . ' ' . $advice));
        }

        $when = $this->formatter->monthList($months);
        $lastPrunedOn = null !== $lastPruned ? ' ' . $this->translator->trans('recommendation.pruning.last', ['date' => $this->formatter->longDate($lastPruned)]) : '';

        $periodStart = self::pruningPeriodStart($months, $today);
        if (null === $periodStart) {
            return new Recommendation(RecommendationLevel::Info, RecommendationSubject::Pruning, trim($this->translator->trans('recommendation.pruning.in', ['months' => $when]) . ' ' . $advice) . $lastPrunedOn);
        }

        if (null !== $lastPruned && $lastPruned->format('Y-m-d') >= $periodStart->format('Y-m-d')) {
            return new Recommendation(RecommendationLevel::Ok, RecommendationSubject::Pruning, $this->translator->trans('recommendation.pruning.done', [
                'date' => $this->formatter->longDate($lastPruned),
                'months' => $when,
            ]));
        }

        return new Recommendation(RecommendationLevel::Action, RecommendationSubject::Pruning, trim($this->translator->trans('recommendation.pruning.now', ['months' => $when]) . ' ' . $advice) . $lastPrunedOn);
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
}
