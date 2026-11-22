<?php

namespace App\Tests\Unit\Mcp;

use App\Mcp\CareSheetInput;
use App\Plant\Exception\InvalidCareSheetException;
use App\Plant\Model\CareSheetField;
use App\Plant\Model\Confidence;
use App\Plant\Model\Exposure;
use App\Plant\Model\Source;
use App\Plant\Model\Watering;
use App\Tests\Integration\Mcp\PlantToolsTest;
use PHPUnit\Framework\TestCase;

final class CareSheetInputTest extends TestCase
{
    public function testTheArgumentOfProposePlantBecomesACareSheet(): void
    {
        /** @var array<string, mixed> $input */
        $input = PlantToolsTest::proposal()['care_sheet'];
        $input['pruning_advice'] = '  Cut last year\'s stems back to two pairs of buds. ';
        $input['sources'] = [['name' => 'Trefle', 'url' => 'https://trefle.io/api/v1/species/191052'], ['name' => 'LLM knowledge']];

        $careSheet = CareSheetInput::toCareSheet($input);

        self::assertSame(-25, $careSheet->getMinTemperatureC());
        self::assertSame(Exposure::Sun, $careSheet->getExposure());
        self::assertSame(Watering::Medium, $careSheet->getWatering());
        self::assertSame([2, 3], $careSheet->getPruningMonths());
        self::assertSame('Cut last year\'s stems back to two pairs of buds.', $careSheet->getPruningAdvice());
        self::assertSame(Confidence::Low, $careSheet->getConfidenceOf(CareSheetField::CuttingMonths));
        self::assertSame([['Trefle', 'https://trefle.io/api/v1/species/191052'], ['LLM knowledge', null]], array_map(static fn (Source $source): array => [$source->name, $source->url], $careSheet->getSources()));
    }

    public function testTheSchemaRequiresEveryFieldOfTheCareSheet(): void
    {
        self::assertSame(CareSheetField::values(), \array_slice(CareSheetInput::SCHEMA['required'], 0, \count(CareSheetField::cases())));
        self::assertSame(CareSheetField::values(), CareSheetInput::SCHEMA['properties']['confidence']['required']);
        foreach (CareSheetInput::SCHEMA['properties'] as $name => $property) {
            self::assertNotEmpty($property['description'] ?? null, \sprintf('"%s" is described for the model.', $name));
        }
    }

    public function testTheInvariantsOfTheCareSheetStillHold(): void
    {
        /** @var array<string, mixed> $input */
        $input = PlantToolsTest::proposal()['care_sheet'];
        $input['cutting_months'] = [13];

        $this->expectException(InvalidCareSheetException::class);

        CareSheetInput::toCareSheet($input);
    }
}
