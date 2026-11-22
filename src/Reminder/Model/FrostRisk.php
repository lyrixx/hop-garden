<?php

namespace App\Reminder\Model;

use App\DataSource\OpenMeteo\Model\DailyMinimum;

final readonly class FrostRisk
{
    /**
     * @param non-empty-list<DailyMinimum> $days the forecast days colder than the threshold, in date order
     */
    public function __construct(
        public float $thresholdC,
        public array $days,
    ) {
    }

    public function firstDay(): DailyMinimum
    {
        return $this->days[0];
    }

    public function lastDay(): DailyMinimum
    {
        return $this->days[\count($this->days) - 1];
    }
}
