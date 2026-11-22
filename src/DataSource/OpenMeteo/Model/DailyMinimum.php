<?php

namespace App\DataSource\OpenMeteo\Model;

final readonly class DailyMinimum
{
    public function __construct(
        public \DateTimeImmutable $date,
        public float $minTemperatureC,
    ) {
    }

    public function isBelow(float $thresholdC): bool
    {
        return $this->minTemperatureC < $thresholdC;
    }
}
