<?php

namespace App\Reminder\Model;

use App\DataSource\OpenMeteo\Model\DailyMinimum;
use App\Plant\Entity\Plant;

/**
 * A day of the frost forecast, with the plants colder than their threshold on that day, frost reminder or not.
 */
final readonly class ForecastDay
{
    /**
     * @param list<Plant> $plantsAtRisk
     */
    public function __construct(
        public DailyMinimum $minimum,
        public array $plantsAtRisk,
    ) {
    }
}
