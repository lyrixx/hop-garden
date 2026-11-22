<?php

namespace App\Tests\Factory;

use App\Plant\Model\CareSheet;
use App\Plant\Model\CareSheetField;
use App\Plant\Model\Confidence;
use App\Plant\Model\Exposure;
use App\Plant\Model\Plant;
use App\Plant\Model\Source;
use App\Plant\Model\Watering;

final class PlantFactory
{
    /**
     * @param list<int> $pruningMonths
     * @param list<int> $deadheadingMonths
     * @param list<int> $cuttingMonths
     */
    public static function careSheet(
        int $minTemperatureC = -15,
        bool $frostSensitive = false,
        array $pruningMonths = [3],
        array $deadheadingMonths = [7, 8],
        array $cuttingMonths = [6],
        Exposure $exposure = Exposure::PartShade,
        Watering $watering = Watering::High,
    ): CareSheet {
        $confidence = [];
        foreach (CareSheetField::cases() as $field) {
            $confidence[$field->value] = Confidence::High;
        }
        $confidence[CareSheetField::MinTemperatureC->value] = Confidence::Low;

        return new CareSheet(
            $minTemperatureC,
            $frostSensitive,
            $exposure,
            $watering,
            $pruningMonths,
            $deadheadingMonths,
            $cuttingMonths,
            'Cut the faded flower heads back to the first pair of buds.',
            'Take softwood cuttings of 10 cm.',
            $confidence,
            [new Source('Trefle'), new Source(Source::LLM_KNOWLEDGE)],
        );
    }

    public static function draft(string $commonName = 'Bigleaf hydrangea', ?CareSheet $careSheet = null): Plant
    {
        return Plant::propose($commonName, 'Hydrangea macrophylla', 'Hydrangeaceae', $careSheet ?? self::careSheet(), new \DateTimeImmutable('2026-11-01 10:00'));
    }

    public static function validated(string $commonName = 'Bigleaf hydrangea', ?CareSheet $careSheet = null): Plant
    {
        $plant = self::draft($commonName, $careSheet);
        $plant->validate(new \DateTimeImmutable('2026-11-01 10:05'));

        return $plant;
    }
}
