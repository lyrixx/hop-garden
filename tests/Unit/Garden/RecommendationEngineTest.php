<?php

namespace App\Tests\Unit\Garden;

use App\DataSource\OpenMeteo\Model\DailyMinimum;
use App\DataSource\OpenMeteo\Model\FrostForecast;
use App\Factory\CareSheetFactory;
use App\Factory\PlantingFactory;
use App\Factory\SpaceFactory;
use App\Garden\Entity\Planting;
use App\Garden\Entity\Space;
use App\Garden\Model\CareTask;
use App\Garden\Model\Soil;
use App\Garden\Recommendation\Model\Recommendation;
use App\Garden\Recommendation\Model\RecommendationLevel;
use App\Garden\Recommendation\Model\RecommendationSubject;
use App\Garden\Recommendation\RecommendationEngine;
use App\Plant\Entity\CareSheet;
use App\Plant\Model\Exposure;
use App\Plant\Model\Watering;
use App\Reminder\FrostRiskEvaluator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RecommendationEngineTest extends TestCase
{
    private RecommendationEngine $engine;

    protected function setUp(): void
    {
        $this->engine = new RecommendationEngine(new FrostRiskEvaluator());
    }

    public function testTheSameExposureSuitsIt(): void
    {
        $recommendation = $this->recommend(RecommendationSubject::Exposure, self::planting(Exposure::PartShade), CareSheetFactory::createOne(['exposure' => Exposure::PartShade]));

        self::assertSame(RecommendationLevel::Ok, $recommendation->level);
        self::assertSame('Part shade suits it.', $recommendation->message);
    }

    public function testAnExposureOneStepAwayIsAnAdvice(): void
    {
        $recommendation = $this->recommend(RecommendationSubject::Exposure, self::planting(Exposure::PartShade), CareSheetFactory::createOne(['exposure' => Exposure::Sun]));

        self::assertSame(RecommendationLevel::Info, $recommendation->level);
        self::assertSame('It prefers full sun and gets part shade here: it should cope, but would do better in full sun.', $recommendation->message);
    }

    public function testTheOppositeExposureIsAWarning(): void
    {
        $recommendation = $this->recommend(RecommendationSubject::Exposure, self::planting(Exposure::Shade), CareSheetFactory::createOne(['exposure' => Exposure::Sun]));

        self::assertSame(RecommendationLevel::Warning, $recommendation->level);
        self::assertSame('It needs full sun and gets shade here: move it to a spot in full sun, in autumn or early spring.', $recommendation->message);
    }

    public function testAnUnknownExposureIsAskedFor(): void
    {
        $planting = PlantingFactory::createOne(['name' => 'Panicled hydrangea', 'trefleId' => 191052, 'scientificName' => 'Hydrangea paniculata']);
        $careSheet = CareSheetFactory::createOne(['exposure' => Exposure::Sun, 'watering' => Watering::High]);

        $exposure = $this->recommend(RecommendationSubject::Exposure, $planting, $careSheet);
        self::assertSame(RecommendationLevel::Info, $exposure->level, 'Not a warning: nothing is wrong yet.');
        self::assertSame('Tell us where it grows, with "Edit garden info": its care sheet asks for full sun.', $exposure->message);

        $watering = $this->recommend(RecommendationSubject::Watering, $planting, $careSheet);
        self::assertSame('It needs plenty of water: keep the soil moist, especially in summer.', $watering->message);
    }

    public function testTheUnknownExposureOfItsSpaceIsAskedFor(): void
    {
        $shed = SpaceFactory::createOne(['name' => 'By the shed', 'soil' => Soil::Clay]);
        $planting = PlantingFactory::new()->inSpace($shed)->create(['name' => 'Panicled hydrangea']);

        $exposure = $this->recommend(RecommendationSubject::Exposure, $planting, CareSheetFactory::createOne(['exposure' => Exposure::Sun]));

        self::assertSame(RecommendationLevel::Info, $exposure->level);
        self::assertSame('Tell us the exposure of By the shed, in "My spaces": its care sheet asks for full sun.', $exposure->message);
    }

    public function testTheExposureFollowsTheSpace(): void
    {
        $shed = SpaceFactory::createOne(['name' => 'By the shed', 'exposure' => Exposure::Shade]);
        $planting = PlantingFactory::new()->inSpace($shed)->create(['name' => 'Panicled hydrangea']);
        $careSheet = CareSheetFactory::createOne(['exposure' => Exposure::Sun]);
        self::assertSame(RecommendationLevel::Warning, $this->recommend(RecommendationSubject::Exposure, $planting, $careSheet)->level);

        $shed->revise('By the shed', Exposure::Sun, null);

        self::assertSame('Full sun suits it.', $this->recommend(RecommendationSubject::Exposure, $planting, $careSheet)->message);
    }

    public function testAThirstyPlantInFullSun(): void
    {
        $planting = self::planting(Exposure::Sun);
        $planting->recordCare(CareTask::Watering, new \DateTimeImmutable('2026-07-02'));

        $recommendation = $this->recommend(RecommendationSubject::Watering, $planting, CareSheetFactory::createOne(['watering' => Watering::High]), today: '2026-07-04');

        self::assertSame(RecommendationLevel::Info, $recommendation->level);
        self::assertSame('It needs plenty of water and gets full sun here: water it often in summer, and mulch the soil to keep it moist. Last watered on 2 July 2026.', $recommendation->message);
    }

    public function testAForecastFrostOnAnUnprotectedPlantIsAWarning(): void
    {
        $careSheet = CareSheetFactory::createOne(['minTemperatureC' => -3, 'frostSensitive' => true]);

        $recommendation = $this->recommend(RecommendationSubject::Frost, self::planting(), $careSheet, '2026-12-01', self::frostForecast());

        self::assertSame(RecommendationLevel::Warning, $recommendation->level);
        self::assertSame('Frost expected from Tuesday 1 December, down to -12.4 °C on Thursday 3 December. It is frost sensitive: bring it indoors or shelter it.', $recommendation->message);
    }

    public function testAForecastFrostBelowTheHardinessOfAHardyPlant(): void
    {
        $careSheet = CareSheetFactory::createOne(['minTemperatureC' => -12]);

        $recommendation = $this->recommend(RecommendationSubject::Frost, self::planting(), $careSheet, '2026-12-01', self::frostForecast());

        self::assertSame(RecommendationLevel::Warning, $recommendation->level);
        // -12 °C + 2 °C of margin: the -12.4 °C of Thursday only
        self::assertSame('Frost expected on Thursday 3 December (-12.4 °C), colder than its hardiness (-12 °C): cover it with a winter fleece.', $recommendation->message);
    }

    public function testAProtectionOfThisWinterCalmsTheFrostWarning(): void
    {
        $planting = self::planting();
        $planting->recordCare(CareTask::FrostProtection, new \DateTimeImmutable('2026-11-20'));

        $recommendation = $this->recommend(RecommendationSubject::Frost, $planting, CareSheetFactory::createOne(['minTemperatureC' => -3, 'frostSensitive' => true]), '2026-12-01', self::frostForecast());

        self::assertSame(RecommendationLevel::Info, $recommendation->level);
        self::assertStringEndsWith('Sheltered on 20 November 2026: check that the protection holds.', $recommendation->message);
    }

    public function testTheProtectionOfLastWinterDoesNotCount(): void
    {
        $planting = self::planting();
        $planting->recordCare(CareTask::FrostProtection, new \DateTimeImmutable('2025-12-01'));

        $recommendation = $this->recommend(RecommendationSubject::Frost, $planting, CareSheetFactory::createOne(['minTemperatureC' => -3, 'frostSensitive' => true]), '2026-12-01', self::frostForecast());

        self::assertSame(RecommendationLevel::Warning, $recommendation->level);
    }

    public function testAProtectionOfLastAutumnStillCountsInJanuary(): void
    {
        $planting = self::planting();
        $planting->recordCare(CareTask::FrostProtection, new \DateTimeImmutable('2026-10-15'));

        $recommendation = $this->recommend(RecommendationSubject::Frost, $planting, CareSheetFactory::createOne(['minTemperatureC' => -3, 'frostSensitive' => true]), '2027-01-10', self::frostForecast('2027-01-10'));

        self::assertSame(RecommendationLevel::Info, $recommendation->level);
    }

    public function testAHardyPlantAboveTheForecast(): void
    {
        $recommendation = $this->recommend(RecommendationSubject::Frost, self::planting(), CareSheetFactory::createOne(['minTemperatureC' => -20]), '2026-12-01', self::frostForecast());

        self::assertSame(RecommendationLevel::Ok, $recommendation->level);
        self::assertSame('Hardy down to -20 °C: the forecast stays above.', $recommendation->message);
    }

    public function testWithoutForecastAFrostSensitivePlantIsAReminderInWinter(): void
    {
        $recommendation = $this->recommend(RecommendationSubject::Frost, self::planting(), CareSheetFactory::createOne(['minTemperatureC' => -3, 'frostSensitive' => true]), '2026-12-01');

        self::assertSame(RecommendationLevel::Info, $recommendation->level);
        self::assertSame('Frost sensitive (hardy down to -3 °C), and no frost protection recorded this winter: bring it indoors or shelter it.', $recommendation->message);
    }

    public function testInAPruningMonthWithoutPruning(): void
    {
        $recommendation = $this->recommend(RecommendationSubject::Pruning, self::planting(), CareSheetFactory::createOne(['pruningMonths' => [2, 3]]), '2026-03-15');

        self::assertSame(RecommendationLevel::Action, $recommendation->level);
        self::assertSame('Time to prune it (February and March). Cut the faded flower heads back to the first pair of buds.', $recommendation->message);
    }

    public function testAPruningOfThisSeasonIsDone(): void
    {
        $planting = self::planting();
        $planting->recordCare(CareTask::Pruning, new \DateTimeImmutable('2026-02-20'));

        $recommendation = $this->recommend(RecommendationSubject::Pruning, $planting, CareSheetFactory::createOne(['pruningMonths' => [2, 3]]), '2026-03-15');

        self::assertSame(RecommendationLevel::Ok, $recommendation->level);
        self::assertSame('Pruned on 20 February 2026: done for this season. Next time in February and March.', $recommendation->message);
    }

    public function testAPruningOfLastYearDoesNotCount(): void
    {
        $planting = self::planting();
        $planting->recordCare(CareTask::Pruning, new \DateTimeImmutable('2025-03-10'));

        $recommendation = $this->recommend(RecommendationSubject::Pruning, $planting, CareSheetFactory::createOne(['pruningMonths' => [2, 3]]), '2026-03-15');

        self::assertSame(RecommendationLevel::Action, $recommendation->level);
        self::assertStringEndsWith('Last pruned on 10 March 2025.', $recommendation->message);
    }

    /**
     * @param list<int> $pruningMonths
     */
    #[DataProvider('provideThePruningPeriodHoldingTodayCases')]
    public function testThePruningPeriodHoldingToday(array $pruningMonths, string $prunedOn, string $today, RecommendationLevel $expected): void
    {
        $planting = self::planting();
        $planting->recordCare(CareTask::Pruning, new \DateTimeImmutable($prunedOn));

        $recommendation = $this->recommend(RecommendationSubject::Pruning, $planting, CareSheetFactory::createOne(['pruningMonths' => $pruningMonths]), $today);

        self::assertSame($expected, $recommendation->level);
    }

    /**
     * @return iterable<string, array{list<int>, string, string, RecommendationLevel}>
     */
    public static function provideThePruningPeriodHoldingTodayCases(): iterable
    {
        yield 'across the new year, pruned in November' => [[1, 2, 11, 12], '2026-11-20', '2027-01-15', RecommendationLevel::Ok];
        yield 'across the new year, pruned before the period' => [[1, 2, 11, 12], '2026-10-30', '2027-01-15', RecommendationLevel::Action];
        yield 'two periods a year, pruned in the first one' => [[3, 9], '2026-03-10', '2026-09-05', RecommendationLevel::Action];
        yield 'every month, pruned 10 months ago' => [range(1, 12), '2025-08-01', '2026-06-15', RecommendationLevel::Ok];
        yield 'every month, pruned a year ago' => [range(1, 12), '2025-06-01', '2026-06-15', RecommendationLevel::Action];
    }

    public function testOutOfSeasonItTellsWhenAndHowToPrune(): void
    {
        $recommendation = $this->recommend(RecommendationSubject::Pruning, self::planting(), CareSheetFactory::createOne(['pruningMonths' => [2, 3]]), '2026-07-01');

        self::assertSame(RecommendationLevel::Info, $recommendation->level);
        self::assertSame('Prune it in February and March. Cut the faded flower heads back to the first pair of buds.', $recommendation->message);
    }

    public function testTheMostPressingFirst(): void
    {
        $careSheet = CareSheetFactory::createOne(['minTemperatureC' => -3, 'frostSensitive' => true, 'pruningMonths' => [12], 'exposure' => Exposure::Sun, 'watering' => Watering::Medium]);

        $recommendations = $this->engine->recommend(self::planting(Exposure::Sun), $careSheet, new \DateTimeImmutable('2026-12-01'), self::frostForecast());

        self::assertSame(
            [
                [RecommendationSubject::Frost, RecommendationLevel::Warning],
                [RecommendationSubject::Pruning, RecommendationLevel::Action],
                [RecommendationSubject::Watering, RecommendationLevel::Info],
                [RecommendationSubject::Exposure, RecommendationLevel::Ok],
            ],
            array_map(static fn (Recommendation $recommendation): array => [$recommendation->subject, $recommendation->level], $recommendations),
        );
    }

    #[DataProvider('provideTheWateringFollowsTheNeedsAndTheExposureCases')]
    public function testTheWateringFollowsTheNeedsAndTheExposure(Watering $watering, ?Exposure $exposure, string $expected): void
    {
        $planting = PlantingFactory::new()->inSpace(self::space($exposure))->create(['name' => 'Hydrangea by the shed']);

        self::assertSame($expected, $this->recommend(RecommendationSubject::Watering, $planting, CareSheetFactory::createOne(['watering' => $watering]))->message);
    }

    /**
     * @return iterable<string, array{Watering, ?Exposure, string}>
     */
    public static function provideTheWateringFollowsTheNeedsAndTheExposureCases(): iterable
    {
        yield 'thirsty, in part shade' => [Watering::High, Exposure::PartShade, 'It needs plenty of water: keep the soil moist, especially in summer.'];
        yield 'average' => [Watering::Medium, Exposure::Sun, 'Water it during dry spells.'];
        yield 'sober, in the shade' => [Watering::Low, Exposure::Shade, 'It needs little water, and the shade keeps the soil moist: water it only during long droughts.'];
        yield 'sober, in the sun' => [Watering::Low, Exposure::Sun, 'It needs little water: water it only during long droughts, and never leave it in soggy soil.'];
        yield 'sober, exposure unknown' => [Watering::Low, null, 'It needs little water: water it only during long droughts, and never leave it in soggy soil.'];
    }

    public function testAFrostOnASingleDay(): void
    {
        $forecast = new FrostForecast(46.6, 2.4, [new DailyMinimum(new \DateTimeImmutable('2026-12-01'), 2.0), new DailyMinimum(new \DateTimeImmutable('2026-12-02'), -1.5)]);

        $recommendation = $this->recommend(RecommendationSubject::Frost, self::planting(), CareSheetFactory::createOne(['minTemperatureC' => -3, 'frostSensitive' => true]), '2026-12-01', $forecast);

        self::assertSame('Frost expected on Wednesday 2 December (-1.5 °C). It is frost sensitive: bring it indoors or shelter it.', $recommendation->message);
    }

    public function testWithoutForecastAHardyPlantReliesOnItsHardiness(): void
    {
        $recommendation = $this->recommend(RecommendationSubject::Frost, self::planting(), CareSheetFactory::createOne(['minTemperatureC' => -15]), '2026-12-01');

        self::assertSame(RecommendationLevel::Ok, $recommendation->level);
        self::assertSame('Hardy down to -15 °C.', $recommendation->message);
    }

    public function testAFrostSensitivePlantOutOfTheColdSeason(): void
    {
        $recommendation = $this->recommend(RecommendationSubject::Frost, self::planting(), CareSheetFactory::createOne(['minTemperatureC' => 5, 'frostSensitive' => true]), '2026-07-01');

        self::assertSame(RecommendationLevel::Info, $recommendation->level);
        self::assertSame('Frost sensitive (hardy down to 5 °C): bring it indoors or shelter it before the first autumn frost.', $recommendation->message);
    }

    public function testWithoutForecastAShelteredFrostSensitivePlantIsFine(): void
    {
        $planting = self::planting();
        $planting->recordCare(CareTask::FrostProtection, new \DateTimeImmutable('2026-11-20'));

        $recommendation = $this->recommend(RecommendationSubject::Frost, $planting, CareSheetFactory::createOne(['minTemperatureC' => -3, 'frostSensitive' => true]), '2026-12-01');

        self::assertSame(RecommendationLevel::Ok, $recommendation->level);
        self::assertSame('Sheltered from frost on 20 November 2026.', $recommendation->message);
    }

    public function testAPlantWithoutPruningMonths(): void
    {
        $recommendation = $this->recommend(RecommendationSubject::Pruning, self::planting(), CareSheetFactory::createOne(['pruningMonths' => []]), '2026-03-15');

        self::assertSame(RecommendationLevel::Ok, $recommendation->level);
        self::assertSame('No regular pruning in its care sheet. Cut the faded flower heads back to the first pair of buds.', $recommendation->message);
    }

    public function testOutOfSeasonTheLastPruningIsRecalled(): void
    {
        $planting = self::planting();
        $planting->recordCare(CareTask::Pruning, new \DateTimeImmutable('2026-03-10'));

        $recommendation = $this->recommend(RecommendationSubject::Pruning, $planting, CareSheetFactory::createOne(['pruningMonths' => [3]]), '2026-07-01');

        self::assertSame('Prune it in March. Cut the faded flower heads back to the first pair of buds. Last pruned on 10 March 2026.', $recommendation->message);
    }

    private function recommend(RecommendationSubject $subject, Planting $planting, CareSheet $careSheet, string $today = '2026-12-01', ?FrostForecast $forecast = null): Recommendation
    {
        foreach ($this->engine->recommend($planting, $careSheet, new \DateTimeImmutable($today), $forecast) as $recommendation) {
            if ($recommendation->subject === $subject) {
                return $recommendation;
            }
        }

        self::fail(\sprintf('No recommendation about %s.', $subject->value));
    }

    private static function planting(Exposure $exposure = Exposure::PartShade): Planting
    {
        return PlantingFactory::new()->inSpace(self::space($exposure))->create(['name' => 'Hydrangea by the shed', 'trefleId' => 191047, 'scientificName' => 'Hydrangea macrophylla']);
    }

    private static function space(?Exposure $exposure): Space
    {
        return SpaceFactory::createOne(['name' => 'By the shed', 'exposure' => $exposure]);
    }

    /**
     * The cold snap of the demo mode: -3.2 °C, -8.5 °C, -12.4 °C.
     */
    private static function frostForecast(string $firstDay = '2026-12-01'): FrostForecast
    {
        $day = new \DateTimeImmutable($firstDay);

        return new FrostForecast(46.6, 2.4, [
            new DailyMinimum($day, -3.2),
            new DailyMinimum($day->modify('+1 day'), -8.5),
            new DailyMinimum($day->modify('+2 days'), -12.4),
        ]);
    }
}
