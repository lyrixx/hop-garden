<?php

namespace App\Tests\Unit\Plant;

use App\Plant\Exception\InvalidCareSheetException;
use App\Plant\Model\CareSheet;
use App\Plant\Model\CareSheetField;
use App\Plant\Model\Confidence;
use App\Plant\Model\Exposure;
use App\Plant\Model\Source;
use App\Plant\Model\Watering;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

final class CareSheetTest extends TestCase
{
    #[TestWith([-60])]
    #[TestWith([30])]
    public function testTheHardinessBoundsAreAccepted(int $minTemperatureC): void
    {
        self::assertSame($minTemperatureC, self::careSheet(minTemperatureC: $minTemperatureC)->getMinTemperatureC());
    }

    #[TestWith([-61])]
    #[TestWith([31])]
    public function testAnImpossibleHardinessIsRefused(int $minTemperatureC): void
    {
        $this->expectException(InvalidCareSheetException::class);
        $this->expectExceptionMessageIs(\sprintf('The minimum temperature must be between -60 and 30 °C, got %d.', $minTemperatureC));

        self::careSheet(minTemperatureC: $minTemperatureC);
    }

    public function testMonthsAreSortedOnce(): void
    {
        $careSheet = self::careSheet(pruningMonths: [11, 3, 11, 2], deadheadingMonths: [8, 7, 7], cuttingMonths: [9, 6]);

        self::assertSame([2, 3, 11], $careSheet->getPruningMonths());
        self::assertSame([7, 8], $careSheet->getDeadheadingMonths());
        self::assertSame([6, 9], $careSheet->getCuttingMonths());
    }

    /**
     * @param list<int> $pruningMonths
     * @param list<int> $deadheadingMonths
     * @param list<int> $cuttingMonths
     */
    #[DataProvider('provideAMonthOutOfTheYearIsRefusedCases')]
    public function testAMonthOutOfTheYearIsRefused(array $pruningMonths, array $deadheadingMonths, array $cuttingMonths, int $month): void
    {
        $this->expectException(InvalidCareSheetException::class);
        $this->expectExceptionMessageIs(\sprintf('A month must be between 1 and 12, got %d.', $month));

        self::careSheet(pruningMonths: $pruningMonths, deadheadingMonths: $deadheadingMonths, cuttingMonths: $cuttingMonths);
    }

    /**
     * @return iterable<string, array{list<int>, list<int>, list<int>, int}>
     */
    public static function provideAMonthOutOfTheYearIsRefusedCases(): iterable
    {
        yield 'pruning in month 0' => [[0, 3], [], [], 0];
        yield 'deadheading in month 13' => [[], [13], [], 13];
        yield 'cuttings in month -1' => [[], [], [-1], -1];
    }

    public function testAnUnknownFieldInTheConfidenceMapIsRefused(): void
    {
        $this->expectException(InvalidCareSheetException::class);
        $this->expectExceptionMessageIs('Unknown care sheet field "soil" in the confidence map.');

        self::careSheet(confidence: ['soil' => Confidence::High]);
    }

    public function testAFieldWithoutConfidenceIsToReview(): void
    {
        $careSheet = self::careSheet(confidence: [CareSheetField::Exposure->value => Confidence::High]);

        self::assertSame(Confidence::High, $careSheet->getConfidenceOf(CareSheetField::Exposure));
        self::assertSame(Confidence::Low, $careSheet->getConfidenceOf(CareSheetField::Watering));
        self::assertSame(CareSheetField::values(), array_keys($careSheet->getConfidences()), 'Every field has a confidence.');
        self::assertSame(Confidence::Low, $careSheet->getConfidences()[CareSheetField::PruningAdvice->value]);
    }

    public function testSourcesKeepTheirUrl(): void
    {
        $careSheet = self::careSheet(sources: [new Source('Trefle', 'https://trefle.io/api/v1/species/191052'), new Source(Source::LLM_KNOWLEDGE)]);

        self::assertSame([['Trefle', 'https://trefle.io/api/v1/species/191052'], ['LLM knowledge', null]], array_map(static fn (Source $source): array => [$source->name, $source->url], $careSheet->getSources()));
        self::assertFalse($careSheet->isLlmOnly());
    }

    public function testASheetBackedByTheLlmAlone(): void
    {
        self::assertTrue(self::careSheet(sources: [new Source(Source::LLM_KNOWLEDGE)])->isLlmOnly());
        self::assertFalse(self::careSheet(sources: [new Source('You')])->isLlmOnly());
    }

    /**
     * @param list<int>                 $pruningMonths
     * @param list<int>                 $deadheadingMonths
     * @param list<int>                 $cuttingMonths
     * @param array<string, Confidence> $confidence
     * @param list<Source>              $sources
     */
    private static function careSheet(
        int $minTemperatureC = -15,
        array $pruningMonths = [3],
        array $deadheadingMonths = [],
        array $cuttingMonths = [],
        array $confidence = [],
        array $sources = [new Source(Source::LLM_KNOWLEDGE)],
    ): CareSheet {
        return new CareSheet($minTemperatureC, false, Exposure::Sun, Watering::Medium, $pruningMonths, $deadheadingMonths, $cuttingMonths, 'Prune.', '', $confidence, $sources);
    }
}
