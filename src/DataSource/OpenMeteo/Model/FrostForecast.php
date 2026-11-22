<?php

namespace App\DataSource\OpenMeteo\Model;

final readonly class FrostForecast
{
    /**
     * @param list<DailyMinimum> $days
     */
    public function __construct(
        public float $latitude,
        public float $longitude,
        public array $days,
    ) {
    }

    /**
     * @return list<DailyMinimum>
     */
    public function daysBelow(float $thresholdC): array
    {
        return array_values(array_filter(
            $this->days,
            static fn (DailyMinimum $day): bool => $day->isBelow($thresholdC),
        ));
    }

    public function coldest(): ?DailyMinimum
    {
        $coldest = null;
        foreach ($this->days as $day) {
            if (null === $coldest || $day->minTemperatureC < $coldest->minTemperatureC) {
                $coldest = $day;
            }
        }

        return $coldest;
    }
}
