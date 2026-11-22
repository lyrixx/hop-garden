<?php

namespace App\Tests\Unit\Plant;

use App\Locale\IntlFormatter;
use App\Plant\CareSheetPresenter;
use App\Plant\Entity\CareSheet;
use App\Plant\Model\CareSheetField;
use App\Plant\Model\CareSheetRow;
use App\Plant\Model\Confidence;
use App\Plant\Model\Exposure;
use App\Plant\Model\Source;
use App\Plant\Model\Watering;
use App\Tests\Utils\Factory\PlantFactory;
use App\Tests\Utils\Translation\Translators;
use PHPUnit\Framework\TestCase;

final class CareSheetPresenterTest extends TestCase
{
    public function testOneRowPerFieldWithItsConfidence(): void
    {
        $rows = self::presenter()->rows(PlantFactory::careSheet(pruningMonths: [3, 11], deadheadingMonths: [], exposure: Exposure::PartShade, watering: Watering::High));

        self::assertSame(
            [
                ['Hardiness', '-15 °C', 'low'],
                ['Frost sensitive', 'No', 'high'],
                ['Exposure', 'Part shade', 'high'],
                ['Watering', 'High', 'high'],
                ['Pruning', 'March, November', 'high'],
                ['Deadheading', '—', 'high'],
                ['Cuttings', 'June', 'high'],
                ['Pruning advice', 'Cut the faded flower heads back to the first pair of buds.', 'high'],
                ['Cutting advice', 'Take softwood cuttings of 10 cm.', 'high'],
            ],
            array_map(static fn (CareSheetRow $row): array => [$row->field->trans(Translators::for('en')), $row->value, $row->confidence->value], $rows),
        );
    }

    public function testAdviceFitsOnOneLine(): void
    {
        $careSheet = new CareSheet(-3, true, Exposure::Sun, Watering::Low, [], [], [], "Cut back\n\n  in spring,\tlightly. ", '   ', [CareSheetField::FrostSensitive->value => Confidence::High], [new Source(Source::LLM_KNOWLEDGE)]);
        $presenter = self::presenter();

        self::assertSame('Cut back in spring, lightly.', $presenter->valueOf($careSheet, CareSheetField::PruningAdvice));
        self::assertSame('—', $presenter->valueOf($careSheet, CareSheetField::CuttingAdvice));
        self::assertSame('Yes', $presenter->valueOf($careSheet, CareSheetField::FrostSensitive));
        self::assertSame('Full sun', $presenter->valueOf($careSheet, CareSheetField::Exposure));
        self::assertSame('Low', $presenter->valueOf($careSheet, CareSheetField::Watering));
    }

    public function testInFrench(): void
    {
        $rows = self::presenter('fr')->rows(PlantFactory::careSheet(pruningMonths: [3, 11], exposure: Exposure::PartShade, watering: Watering::High));

        self::assertSame(
            ["-15\u{a0}°C", 'Non', 'Mi-ombre', 'Abondant', 'mars, novembre'],
            array_map(static fn (CareSheetRow $row): string => $row->value, \array_slice($rows, 0, 5)),
        );
    }

    private static function presenter(string $locale = 'en'): CareSheetPresenter
    {
        $translator = Translators::for($locale);

        return new CareSheetPresenter($translator, new IntlFormatter($translator));
    }
}
