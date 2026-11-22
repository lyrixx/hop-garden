<?php

namespace App\Tests\Unit\Reminder;

use App\DataSource\OpenMeteo\Model\DailyMinimum;
use App\DataSource\OpenMeteo\Model\FrostForecast;
use App\Factory\CareSheetFactory;
use App\Factory\PlantFactory;
use App\Plant\Entity\Plant;
use App\Reminder\FrostRiskEvaluator;
use App\Reminder\Model\ForecastDay;
use App\Reminder\Model\ReminderType;
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
        $careSheet = CareSheetFactory::createOne(['minTemperatureC' => $minTemperatureC, 'frostSensitive' => $frostSensitive]);

        self::assertSame($expected, (new FrostRiskEvaluator())->thresholdOf($careSheet));
    }

    public function testNoRiskWhenTheForecastStaysAtOrAboveTheThreshold(): void
    {
        $forecast = self::forecast(['2026-12-01' => -13.0, '2026-12-02' => -2.0]);

        self::assertNull((new FrostRiskEvaluator())->evaluate(CareSheetFactory::createOne(['minTemperatureC' => -15]), $forecast), 'Colder than the threshold only.');
    }

    public function testTheFrostDaysInDateOrder(): void
    {
        $forecast = self::forecast(['2026-12-03' => -12.4, '2026-12-01' => -3.2, '2026-12-02' => 1.5]);

        $risk = (new FrostRiskEvaluator())->evaluate(CareSheetFactory::createOne(['minTemperatureC' => -3, 'frostSensitive' => true]), $forecast);

        self::assertNotNull($risk);
        self::assertSame(0.0, $risk->thresholdC);
        self::assertSame(['2026-12-01', '2026-12-03'], array_map(static fn (DailyMinimum $day): string => $day->date->format('Y-m-d'), $risk->days));
        self::assertSame('2026-12-01', $risk->firstDay()->date->format('Y-m-d'));
        self::assertSame('2026-12-03', $risk->lastDay()->date->format('Y-m-d'));
    }

    public function testTheColdestDayOfAFrostRisk(): void
    {
        $forecast = self::forecast(['2026-12-01' => -3.2, '2026-12-02' => -12.4, '2026-12-03' => -12.4]);

        $risk = (new FrostRiskEvaluator())->evaluate(CareSheetFactory::createOne(['minTemperatureC' => -3, 'frostSensitive' => true]), $forecast);

        self::assertSame('2026-12-02', $risk?->coldestDay()->date->format('Y-m-d'), 'The first of the coldest.');
    }

    public function testTheUpcomingFrostsAreTheFrostRemindersAtRisk(): void
    {
        $forecast = self::forecast(['2026-12-02' => 1.5, '2026-12-03' => -3.2]);
        $lemonTree = PlantFactory::new()->validated()->create(['commonName' => 'Lemon tree', 'careSheet' => CareSheetFactory::new(['minTemperatureC' => -3, 'frostSensitive' => true])]);
        $rose = PlantFactory::new()->validated()->create(['commonName' => 'Rose', 'careSheet' => CareSheetFactory::new(['minTemperatureC' => -20])]);
        $reminders = [$lemonTree->enableReminder(ReminderType::Pruning), $lemonTree->enableReminder(ReminderType::Frost), $rose->enableReminder(ReminderType::Frost)];

        $upcoming = (new FrostRiskEvaluator())->upcoming($reminders, $forecast);

        self::assertCount(1, $upcoming, 'Not the calendar reminder, not the hardy rose.');
        self::assertSame($reminders[1], $upcoming[0]->reminder);
        self::assertSame('2026-12-03', $upcoming[0]->startsOn->format('Y-m-d'), 'From its first frost day.');
        self::assertSame(0.0, $upcoming[0]->risk->thresholdC);
    }

    public function testThePlantsAtRiskOfEachDay(): void
    {
        $forecast = self::forecast(['2026-12-01' => -3.2, '2026-12-02' => -8.5, '2026-12-03' => 2.0]);
        $lemonTree = PlantFactory::new()->validated()->create(['commonName' => 'Lemon tree', 'careSheet' => CareSheetFactory::new(['minTemperatureC' => -3, 'frostSensitive' => true])]);
        $hydrangea = PlantFactory::new()->validated()->create(['careSheet' => CareSheetFactory::new(['minTemperatureC' => -10])]);

        $days = (new FrostRiskEvaluator())->plantsAtRiskByDay([$hydrangea, $lemonTree], $forecast);

        self::assertSame(
            [['2026-12-01', ['Lemon tree']], ['2026-12-02', ['Bigleaf hydrangea', 'Lemon tree']], ['2026-12-03', []]],
            array_map(static fn (ForecastDay $day): array => [
                $day->minimum->date->format('Y-m-d'),
                array_map(static fn (Plant $plant): string => $plant->getCommonName(), $day->plantsAtRisk),
            ], $days),
            'Every day of the forecast, in its order; -8.5 °C is colder than -10 °C plus 2 °C.',
        );
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
