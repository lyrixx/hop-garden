<?php

namespace App\Tests\Unit\Reminder;

use App\DataSource\OpenMeteo\Model\DailyMinimum;
use App\DataSource\OpenMeteo\Model\FrostForecast;
use App\Reminder\FrostRiskEvaluator;
use App\Tests\Factory\PlantFactory;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

final class FrostRiskEvaluatorTest extends TestCase
{
    #[TestWith([-15, false, -13.0])]
    #[TestWith([-1, false, 1.0])]
    #[TestWith([-15, true, 0.0])]
    #[TestWith([-1, true, 1.0])]
    #[TestWith([5, true, 7.0])]
    public function testTheThresholdKeepsAMarginAboveTheHardiness(int $minTemperatureC, bool $frostSensitive, float $expected): void
    {
        $careSheet = PlantFactory::careSheet(minTemperatureC: $minTemperatureC, frostSensitive: $frostSensitive);

        self::assertSame($expected, (new FrostRiskEvaluator())->thresholdOf($careSheet));
    }

    public function testNoRiskWhenTheForecastStaysAtOrAboveTheThreshold(): void
    {
        $forecast = self::forecast(['2026-12-01' => -13.0, '2026-12-02' => -2.0]);

        self::assertNull((new FrostRiskEvaluator())->evaluate(PlantFactory::careSheet(minTemperatureC: -15), $forecast), 'Colder than the threshold only.');
    }

    public function testTheFrostDaysInDateOrder(): void
    {
        $forecast = self::forecast(['2026-12-03' => -12.4, '2026-12-01' => -3.2, '2026-12-02' => 1.5]);

        $risk = (new FrostRiskEvaluator())->evaluate(PlantFactory::careSheet(minTemperatureC: -3, frostSensitive: true), $forecast);

        self::assertNotNull($risk);
        self::assertSame(0.0, $risk->thresholdC);
        self::assertSame(['2026-12-01', '2026-12-03'], array_map(static fn (DailyMinimum $day): string => $day->date->format('Y-m-d'), $risk->days));
        self::assertSame('2026-12-01', $risk->firstDay()->date->format('Y-m-d'));
        self::assertSame('2026-12-03', $risk->lastDay()->date->format('Y-m-d'));
    }

    public function testTheColdestDayOfAForecast(): void
    {
        self::assertSame(-12.4, self::forecast(['2026-12-01' => -3.2, '2026-12-02' => -12.4, '2026-12-03' => -8.5])->coldest()?->minTemperatureC);
        self::assertNull(self::forecast([])->coldest());
    }

    /**
     * @param array<string, float> $minimums
     */
    private static function forecast(array $minimums): FrostForecast
    {
        $days = [];
        foreach ($minimums as $date => $minimum) {
            $days[] = new DailyMinimum(new \DateTimeImmutable($date), $minimum);
        }

        return new FrostForecast(46.6, 2.4, $days);
    }
}
