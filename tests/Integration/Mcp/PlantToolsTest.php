<?php

namespace App\Tests\Integration\Mcp;

use App\Factory\CareSheetFactory;
use App\Factory\PlantFactory;
use App\Garden\PlantingRepository;
use App\Plant\Model\CareSheetField;
use App\Plant\Model\Confidence;
use App\Plant\Model\PlantStatus;
use App\Plant\PlantRepository;
use App\Reminder\Model\ReminderType;
use App\Tests\Utils\Http\UnavailableDataSources;
use App\Tests\Utils\Mcp\McpTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Exception\RequestException;
use PHPUnit\Framework\Attributes\DataProvider;

final class PlantToolsTest extends McpTestCase
{
    public function testProposePlantCreatesADraft(): void
    {
        $result = $this->callTool('propose_plant', self::proposal());

        self::assertSame('draft', $result['status']);
        self::assertStringEndsWith('/plants/' . $result['plant_id'], $result['validation_url']);

        $plant = $this->plants()->get(self::gardenOfTheTest(), $result['plant_id']);
        self::assertSame(PlantStatus::Draft, $plant->getStatus());
        self::assertSame('Panicled hydrangea', $plant->getCommonName());
        self::assertSame(-25, $plant->getCareSheet()->getMinTemperatureC());
        self::assertSame([2, 3], $plant->getCareSheet()->getPruningMonths());
        self::assertSame(Confidence::Low, $plant->getCareSheet()->getConfidenceOf(CareSheetField::CuttingMonths));
        self::assertSame(['Trefle', 'LLM knowledge'], array_map(static fn ($source) => $source->name, $plant->getCareSheet()->getSources()));
    }

    public function testProposePlantRejectsAnIncompleteCareSheet(): void
    {
        $proposal = self::proposal();
        unset($proposal['care_sheet']['confidence']);

        $this->expectException(RequestException::class);

        $this->mcp()->callTool('propose_plant', $proposal);
    }

    public function testGetPlant(): void
    {
        $plant = PlantFactory::createOne();

        $result = $this->callTool('get_plant', ['id' => $plant->getId()]);

        self::assertSame('Bigleaf hydrangea', $result['common_name']);
        self::assertSame('draft', $result['status']);
        self::assertSame(-15, $result['care_sheet']['min_temperature_c']);
        self::assertSame('low', $result['care_sheet']['confidence']['min_temperature_c']);
    }

    public function testGetPlantFailsForAnUnknownPlant(): void
    {
        self::assertStringContainsString('No plant with id 999999', $this->callToolExpectingError('get_plant', ['id' => 999999]));
    }

    public function testListCollectionOnlyListsValidatedPlants(): void
    {
        PlantFactory::createOne(['commonName' => 'Draft hydrangea']);
        $validated = PlantFactory::new()->validated()->withEnabledReminders(ReminderType::Pruning)->create(['commonName' => 'Lavender']);

        $result = $this->callTool('list_collection');

        self::assertSame(['Lavender'], array_column($result['plants'], 'common_name'));
        self::assertSame(['pruning'], $result['plants'][0]['enabled_reminders']);
    }

    public function testEnableAndDisableReminder(): void
    {
        $plant = PlantFactory::new()->validated()->create();

        $result = $this->callTool('enable_reminder', ['plant_id' => $plant->getId(), 'type' => 'frost']);
        self::assertSame(['frost'], $result['enabled_reminders']);

        $result = $this->callTool('enable_reminder', ['plant_id' => $plant->getId(), 'type' => 'pruning']);
        self::assertSame(['pruning', 'frost'], $result['enabled_reminders']);

        $result = $this->callTool('disable_reminder', ['plant_id' => $plant->getId(), 'type' => 'frost']);
        self::assertSame(['pruning'], $result['enabled_reminders']);
    }

    public function testRemindersAreRefusedForADraft(): void
    {
        $plant = PlantFactory::createOne();

        self::assertStringContainsString('must validate it', $this->callToolExpectingError('enable_reminder', ['plant_id' => $plant->getId(), 'type' => 'pruning']));
        self::assertStringContainsString('must validate it', $this->callToolExpectingError('disable_reminder', ['plant_id' => $plant->getId(), 'type' => 'pruning']));
    }

    public function testUpcomingReminders(): void
    {
        self::mockTime('2026-02-10 07:00');
        $plant = PlantFactory::new()->validated()->withEnabledReminders(ReminderType::Pruning, ReminderType::Cutting, ReminderType::Frost)->create(['careSheet' => CareSheetFactory::new(['minTemperatureC' => -10, 'pruningMonths' => [3], 'cuttingMonths' => [9]])]);

        $result = $this->callTool('upcoming_reminders', ['days' => 30]);

        self::assertSame([['plant_id' => $plant->getId(), 'plant' => 'Bigleaf hydrangea', 'type' => 'pruning', 'starts_on' => '2026-03-01']], $result['calendar']);
        // -10 °C + 2 °C of margin: the last two days of the recorded cold snap (-8.5 °C and -12.4 °C)
        self::assertSame(['2026-02-11', '2026-02-12'], array_column($result['frost']['plants_at_risk'][0]['frost_days'], 'date'));
        self::assertEqualsWithDelta(-8.0, $result['frost']['plants_at_risk'][0]['threshold_c'], 0.001);
    }

    public function testConfirmPlantValidatesADraft(): void
    {
        $plant = PlantFactory::createOne();

        $result = $this->callTool('confirm_plant', ['id' => $plant->getId()]);

        self::assertSame('validated', $result['status']);
        self::assertTrue($this->plants()->get(self::gardenOfTheTest(), $plant->getId())->isValidated());
        self::assertStringContainsString('already validated', $this->callToolExpectingError('confirm_plant', ['id' => $plant->getId()]));

        self::assertSame([], self::getContainer()->get(PlantingRepository::class)->findBy(['plant' => $plant->getId()]), 'Validated only: propose_planting adds a plant, once the user taps its card.');
    }

    public function testProposePlantTrimsTheNamesAndKeepsTheNotes(): void
    {
        $proposal = self::proposal();
        unset($proposal['trefle_id']);
        $proposal['common_name'] = '  Panicled hydrangea ';
        $proposal['image_url'] = 'https://bs.plantnet.org/paniculata.jpg';
        $proposal['notes'] = 'Flowers on the new wood.';

        $result = $this->callTool('propose_plant', $proposal);

        $plant = $this->plants()->get(self::gardenOfTheTest(), $result['plant_id']);
        self::assertSame('Panicled hydrangea', $plant->getCommonName());
        self::assertNull($plant->getTrefleId(), 'Trefle did not know it.');
        self::assertSame('https://bs.plantnet.org/paniculata.jpg', $plant->getImageUrl());
        self::assertSame('Flowers on the new wood.', $plant->getNotes());
        self::assertStringContainsString('until then, the plant is not validated', $result['next_step']);
    }

    /**
     * @param array<string, mixed> $careSheet
     */
    #[DataProvider('provideProposePlantRejectsAnInvalidCareSheetCases')]
    public function testProposePlantRejectsAnInvalidCareSheet(array $careSheet): void
    {
        /** @var array<string, mixed> $proposedCareSheet */
        $proposedCareSheet = self::proposal()['care_sheet'];

        try {
            $this->mcp()->callTool('propose_plant', ['care_sheet' => array_replace($proposedCareSheet, $careSheet)] + self::proposal());
            self::fail('The care sheet must be refused.');
        } catch (RequestException $e) {
            self::assertStringContainsString('Invalid parameters for tool \'propose_plant\'', $e->getMessage());
        }

        self::assertSame(0, $this->plants()->count(), 'No draft.');
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function provideProposePlantRejectsAnInvalidCareSheetCases(): iterable
    {
        yield 'a month out of the year' => [['pruning_months' => [13]]];
        yield 'the same month twice' => [['cutting_months' => [6, 6]]];
        yield 'an impossible hardiness' => [['min_temperature_c' => -80]];
        yield 'an unknown exposure' => [['exposure' => 'moonlight']];
        yield 'a field that does not exist' => [['soil' => 'clay']];
        yield 'no source' => [['sources' => []]];
    }

    public function testGetPlantRejectsAnIdBelow1(): void
    {
        $this->expectException(RequestException::class);

        $this->mcp()->callTool('get_plant', ['id' => 0]);
    }

    public function testAnEmptyCollection(): void
    {
        self::assertSame(['plants' => []], $this->callTool('list_collection'));
    }

    public function testRemindersOfAnUnknownPlant(): void
    {
        self::assertSame('No plant with id 999999.', $this->callToolExpectingError('enable_reminder', ['plant_id' => 999999, 'type' => 'pruning']));
        self::assertSame('No plant with id 999999.', $this->callToolExpectingError('disable_reminder', ['plant_id' => 999999, 'type' => 'pruning']));
    }

    public function testAnUnknownReminderType(): void
    {
        $plant = PlantFactory::new()->validated()->create();

        $this->expectException(RequestException::class);

        $this->mcp()->callTool('enable_reminder', ['plant_id' => $plant->getId(), 'type' => 'watering']);
    }

    public function testNoUpcomingReminder(): void
    {
        self::assertSame(['calendar' => [], 'frost' => ['plants_at_risk' => [], 'forecast_available' => true]], $this->callTool('upcoming_reminders'));
    }

    public function testUpcomingRemindersWithoutForecast(): void
    {
        UnavailableDataSources::openMeteo(self::getContainer());
        $plant = PlantFactory::new()->validated()->withEnabledReminders(ReminderType::Frost)->create(['careSheet' => CareSheetFactory::new(['frostSensitive' => true])]);

        self::assertSame(['plants_at_risk' => [], 'forecast_available' => false], $this->callTool('upcoming_reminders')['frost']);
    }

    public function testUpcomingRemindersLookAYearAheadAtMost(): void
    {
        $this->expectException(RequestException::class);

        $this->mcp()->callTool('upcoming_reminders', ['days' => 367]);
    }

    public function testConfirmPlantFailsForAnUnknownPlant(): void
    {
        self::assertSame('No plant with id 999999.', $this->callToolExpectingError('confirm_plant', ['id' => 999999]));
    }

    /**
     * @return array<string, mixed>
     */
    public static function proposal(): array
    {
        return [
            'common_name' => 'Panicled hydrangea',
            'scientific_name' => 'Hydrangea paniculata',
            'family' => 'Hydrangeaceae',
            'trefle_id' => 191052,
            'care_sheet' => [
                'min_temperature_c' => -25,
                'frost_sensitive' => false,
                'exposure' => 'sun',
                'watering' => 'medium',
                'pruning_months' => [3, 2],
                'deadheading_months' => [],
                'cutting_months' => [6, 7],
                'pruning_advice' => 'Cut last year\'s stems back to two pairs of buds.',
                'cutting_advice' => 'Softwood cuttings in early summer.',
                'confidence' => [
                    'min_temperature_c' => 'medium',
                    'frost_sensitive' => 'high',
                    'exposure' => 'high',
                    'watering' => 'high',
                    'pruning_months' => 'high',
                    'deadheading_months' => 'medium',
                    'cutting_months' => 'low',
                    'pruning_advice' => 'high',
                    'cutting_advice' => 'medium',
                ],
                'sources' => [
                    ['name' => 'Trefle'],
                    ['name' => 'LLM knowledge'],
                ],
            ],
        ];
    }

    private function plants(): PlantRepository
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        return self::getContainer()->get(PlantRepository::class);
    }
}
