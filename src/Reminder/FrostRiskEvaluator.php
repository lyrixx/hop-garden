<?php

namespace App\Reminder;

use App\DataSource\OpenMeteo\Model\DailyMinimum;
use App\DataSource\OpenMeteo\Model\FrostForecast;
use App\Plant\Entity\CareSheet;
use App\Plant\Entity\Plant;
use App\Reminder\Entity\Reminder;
use App\Reminder\Model\ForecastDay;
use App\Reminder\Model\FrostRisk;
use App\Reminder\Model\UpcomingFrost;

final readonly class FrostRiskEvaluator
{
    /**
     * Safety margin above the hardiness: forecasts at ground level are often colder than announced.
     */
    private const float HARDINESS_MARGIN_C = 2.0;

    public function evaluate(CareSheet $careSheet, FrostForecast $forecast): ?FrostRisk
    {
        $thresholdC = $this->thresholdOf($careSheet);
        $days = $forecast->daysBelow($thresholdC);

        if ([] === $days) {
            return null;
        }

        usort($days, static fn ($a, $b): int => $a->date <=> $b->date);

        return new FrostRisk($thresholdC, $days);
    }

    /**
     * The frost reminders whose plant the forecast threatens, in the given order; the calendar ones are left out.
     *
     * @param list<Reminder> $reminders
     *
     * @return list<UpcomingFrost>
     */
    public function upcoming(array $reminders, FrostForecast $forecast): array
    {
        $upcoming = [];
        foreach ($reminders as $reminder) {
            if ($reminder->getType()->isCalendar()) {
                continue;
            }

            $risk = $this->evaluate($reminder->getPlant()->getCareSheet(), $forecast);
            if (null !== $risk) {
                $upcoming[] = new UpcomingFrost($reminder, $risk);
            }
        }

        return $upcoming;
    }

    /**
     * Each day of the forecast, with the plants it threatens.
     *
     * @param list<Plant> $plants
     *
     * @return list<ForecastDay>
     */
    public function plantsAtRiskByDay(array $plants, FrostForecast $forecast): array
    {
        return array_map(fn (DailyMinimum $day): ForecastDay => new ForecastDay(
            $day,
            array_values(array_filter($plants, fn (Plant $plant): bool => $day->isBelow($this->thresholdOf($plant->getCareSheet())))),
        ), $forecast->days);
    }

    public function thresholdOf(CareSheet $careSheet): float
    {
        $thresholdC = $careSheet->getMinTemperatureC() + self::HARDINESS_MARGIN_C;

        // Any frost damages a frost-sensitive plant, even one whose hardiness is said to be below 0 °C
        if ($careSheet->isFrostSensitive()) {
            return max(0.0, $thresholdC);
        }

        return $thresholdC;
    }
}
