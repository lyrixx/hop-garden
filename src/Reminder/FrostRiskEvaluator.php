<?php

namespace App\Reminder;

use App\DataSource\OpenMeteo\Model\FrostForecast;
use App\Plant\Model\CareSheet;
use App\Reminder\Model\FrostRisk;

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
