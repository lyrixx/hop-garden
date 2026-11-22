<?php

namespace App\Tests\Unit\Plant\Form;

use App\Factory\PlantFactory;
use App\Plant\Entity\CareSheet;
use App\Plant\Form\Model\CareSheetFormData;
use App\Plant\Model\CareSheetField;
use App\Plant\Model\Confidence;
use App\Plant\Model\Exposure;
use App\Plant\Model\Source;
use App\Plant\Model\Watering;
use PHPUnit\Framework\TestCase;

final class CareSheetFormDataTest extends TestCase
{
    public function testAFormSavedAsIsKeepsTheConfidenceOfTheAssistant(): void
    {
        $plant = PlantFactory::createOne(['commonName' => 'Bigleaf hydrangea', 'notes' => 'Grows fast.']);
        $data = CareSheetFormData::fromPlant($plant);

        $careSheet = $data->toCareSheet($plant->getCareSheet());

        self::assertSame(self::valuesOf($plant->getCareSheet()), self::valuesOf($careSheet));
        self::assertSame(Confidence::Low, $careSheet->getConfidenceOf(CareSheetField::MinTemperatureC));
        self::assertNotContains(CareSheetFormData::USER_SOURCE, self::sourceNames($careSheet->getSources()));
        self::assertSame('Bigleaf hydrangea', $data->commonName);
        self::assertSame('Grows fast.', $data->notes);
    }

    public function testAChangedValueIsTheUsersOne(): void
    {
        $confidence = [];
        foreach (CareSheetField::cases() as $field) {
            $confidence[$field->value] = Confidence::Low;
        }
        $previous = new CareSheet(-15, false, Exposure::PartShade, Watering::High, [3], [7, 8], [6], 'Prune.', 'Cuttings.', $confidence, [new Source('Trefle')]);
        $data = CareSheetFormData::fromPlant(PlantFactory::createOne(['careSheet' => $previous]));

        $data->watering = Watering::Low;
        $data->pruningMonths = [11, 2, 2];
        $data->cuttingAdvice = '  Cuttings.  ';
        $careSheet = $data->toCareSheet($previous);

        self::assertSame(Watering::Low, $careSheet->getWatering());
        self::assertSame([2, 11], $careSheet->getPruningMonths());
        self::assertSame('Cuttings.', $careSheet->getCuttingAdvice());
        self::assertSame(Confidence::High, $careSheet->getConfidenceOf(CareSheetField::Watering));
        self::assertSame(Confidence::High, $careSheet->getConfidenceOf(CareSheetField::PruningMonths));
        self::assertSame(Confidence::Low, $careSheet->getConfidenceOf(CareSheetField::CuttingAdvice), 'Only blanks around: unchanged.');
        self::assertSame(Confidence::Low, $careSheet->getConfidenceOf(CareSheetField::Exposure));
        self::assertSame(['Trefle', CareSheetFormData::USER_SOURCE], self::sourceNames($careSheet->getSources()));

        // A second edit does not add the user twice
        $data->watering = Watering::Medium;
        self::assertSame(['Trefle', CareSheetFormData::USER_SOURCE], self::sourceNames($data->toCareSheet($careSheet)->getSources()));
    }

    public function testABlankSheetFilledInByHandStaysWithoutTheAssistant(): void
    {
        $blank = CareSheet::blank([new Source(Source::TREFLE)]);
        $data = CareSheetFormData::fromPlant(PlantFactory::createOne(['careSheet' => $blank]));

        $data->minTemperatureC = -25;
        $careSheet = $data->toCareSheet($blank);

        self::assertFalse($careSheet->hasLlmKnowledge());
        self::assertSame(Confidence::High, $careSheet->getConfidenceOf(CareSheetField::MinTemperatureC));
        self::assertSame(Confidence::Low, $careSheet->getConfidenceOf(CareSheetField::Exposure), 'Left as it was: still to fill in.');
    }

    public function testEmptyAdviceIsAnEmptyString(): void
    {
        $plant = PlantFactory::createOne();
        $data = CareSheetFormData::fromPlant($plant);
        $data->pruningAdvice = null;

        $careSheet = $data->toCareSheet($plant->getCareSheet());

        self::assertSame('', $careSheet->getPruningAdvice());
        self::assertSame(Confidence::High, $careSheet->getConfidenceOf(CareSheetField::PruningAdvice));
    }

    /**
     * @return array<string, mixed>
     */
    private static function valuesOf(CareSheet $careSheet): array
    {
        return [
            'min_temperature_c' => $careSheet->getMinTemperatureC(),
            'frost_sensitive' => $careSheet->isFrostSensitive(),
            'exposure' => $careSheet->getExposure(),
            'watering' => $careSheet->getWatering(),
            'pruning_months' => $careSheet->getPruningMonths(),
            'deadheading_months' => $careSheet->getDeadheadingMonths(),
            'cutting_months' => $careSheet->getCuttingMonths(),
            'pruning_advice' => $careSheet->getPruningAdvice(),
            'cutting_advice' => $careSheet->getCuttingAdvice(),
            'confidence' => $careSheet->getConfidences(),
            'sources' => array_map(static fn (Source $source): array => [$source->name, $source->url], $careSheet->getSources()),
        ];
    }

    /**
     * @param list<Source> $sources
     *
     * @return list<string>
     */
    private static function sourceNames(array $sources): array
    {
        return array_map(static fn (Source $source): string => $source->name, $sources);
    }
}
