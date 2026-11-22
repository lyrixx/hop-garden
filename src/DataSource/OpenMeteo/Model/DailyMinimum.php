<?php

namespace App\DataSource\OpenMeteo\Model;

final readonly class DailyMinimum
{
    public function __construct(
        public \DateTimeImmutable $date,
        public float $minTemperatureC,
    ) {
    }
}
