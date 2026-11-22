<?php

namespace App\Tests\Unit\Garden\Form;

use App\Factory\GardenFactory;
use App\Factory\PlantingFactory;
use App\Factory\SpaceFactory;
use App\Garden\Form\Model\PlantingFormData;
use App\Garden\Model\CareTask;
use PHPUnit\Framework\TestCase;

final class PlantingFormDataTest extends TestCase
{
    public function testANewPlantingIsTrimmed(): void
    {
        $data = new PlantingFormData();
        $data->name = '  Hortensia du portail ';
        $data->trefleId = 191052;
        $data->scientificName = ' Hydrangea paniculata ';
        $data->space = $northBed = SpaceFactory::createOne(['name' => 'North bed']);
        $data->diseases = '   ';
        $data->careRecords = [CareTask::Pruning->value => new \DateTimeImmutable('2026-03-10'), CareTask::Watering->value => null];

        $planting = $data->toPlanting($northBed->getGarden(), new \DateTimeImmutable('2026-11-21 10:00'), 'photo.png');

        self::assertSame('Hortensia du portail', $planting->getName());
        self::assertSame(191052, $planting->getTrefleId());
        self::assertSame('Hydrangea paniculata', $planting->getScientificName());
        self::assertSame($northBed, $planting->getSpace());
        self::assertSame([$planting], $northBed->getPlantings());
        self::assertNull($planting->getDiseases(), 'Blank is nothing.');
        self::assertSame('photo.png', $planting->getPhotoFilename());
        self::assertSame('2026-11-21 10:00', $planting->getCreatedAt()->format('Y-m-d H:i'));
        self::assertSame('2026-03-10', $planting->lastDone(CareTask::Pruning)?->format('Y-m-d'));
        self::assertCount(1, $planting->getCareRecords());
    }

    public function testATrefleIdWithoutSpeciesIsDropped(): void
    {
        $data = new PlantingFormData();
        $data->name = 'The mystery shrub';
        // A species picked, then its name erased
        $data->trefleId = 191052;
        $data->scientificName = ' ';

        $planting = $data->toPlanting(GardenFactory::createOne(), new \DateTimeImmutable('2026-11-21'), null);

        self::assertNull($planting->getScientificName());
        self::assertNull($planting->getTrefleId());
    }

    public function testTheEditFormStartsFromThePlanting(): void
    {
        $northBed = SpaceFactory::createOne(['name' => 'North bed']);
        $planting = PlantingFactory::new()
            ->inSpace($northBed)
            ->withCareRecord(CareTask::Watering, new \DateTimeImmutable('2026-11-10'))
            ->withCareRecord(CareTask::Watering, new \DateTimeImmutable('2026-11-15'))
            ->create(['name' => 'Hydrangea', 'diseases' => 'Aphids.', 'trefleId' => 191047, 'scientificName' => 'Hydrangea macrophylla'])
        ;

        $data = PlantingFormData::fromPlanting($planting);

        self::assertSame('Hydrangea', $data->name);
        self::assertSame(191047, $data->trefleId);
        self::assertSame($northBed, $data->space);
        self::assertSame('Aphids.', $data->diseases);
        self::assertSame(array_map(static fn (CareTask $task): string => $task->value, CareTask::cases()), array_keys($data->careRecords));
        self::assertSame('2026-11-15', $data->careRecords[CareTask::Watering->value]?->format('Y-m-d'), 'The last time.');
        self::assertNull($data->careRecords[CareTask::Pruning->value]);
    }

    public function testAnEditRecordsTheNewCareAndMovesThePlantingToAnotherSpace(): void
    {
        $northBed = SpaceFactory::createOne(['name' => 'North bed']);
        $balcony = SpaceFactory::createOne(['name' => 'Balcony']);
        $lavender = PlantingFactory::new()->inSpace($northBed)->create(['name' => 'Lavender']);
        $sage = PlantingFactory::new()->inSpace($balcony)->create(['name' => 'Sage']);
        $planting = PlantingFactory::new()->inSpace($northBed)->withCareRecord(CareTask::Watering, new \DateTimeImmutable('2026-11-10'))->create(['name' => 'Hydrangea']);

        $data = PlantingFormData::fromPlanting($planting);
        $data->name = ' Hydrangea by the shed ';
        $data->space = $balcony;
        $data->diseases = ' Powdery mildew. ';
        $data->careRecords[CareTask::Watering->value] = new \DateTimeImmutable('2026-11-20');
        $data->applyTo($planting);

        self::assertSame('Hydrangea by the shed', $planting->getName());
        self::assertSame($balcony, $planting->getSpace());
        self::assertSame('Powdery mildew.', $planting->getDiseases());
        self::assertSame(['2026-11-10', '2026-11-20'], array_map(static fn ($record): string => $record->doneOn->format('Y-m-d'), $planting->getCareRecords()), 'The older care stays in the history.');
        self::assertSame([$sage], $planting->getNeighbours());
        self::assertSame([], $lavender->getNeighbours());
    }

    public function testAPlantingMayLeaveItsSpace(): void
    {
        $northBed = SpaceFactory::createOne(['name' => 'North bed']);
        $planting = PlantingFactory::new()->inSpace($northBed)->create(['name' => 'Hydrangea']);

        $data = PlantingFormData::fromPlanting($planting);
        $data->space = null;
        $data->applyTo($planting);

        self::assertNull($planting->getSpace());
        self::assertSame([], $northBed->getPlantings());
    }
}
