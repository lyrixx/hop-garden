<?php

namespace App\Tests\Unit\Garden\Form;

use App\Garden\Entity\Planting;
use App\Garden\Entity\Space;
use App\Garden\Form\Model\PlantingFormData;
use App\Garden\Model\CareTask;
use App\Garden\Model\Soil;
use App\Plant\Model\Exposure;
use PHPUnit\Framework\TestCase;

final class PlantingFormDataTest extends TestCase
{
    public function testANewPlantingIsTrimmed(): void
    {
        $data = new PlantingFormData();
        $data->name = '  Hortensia du portail ';
        $data->trefleId = 191052;
        $data->scientificName = ' Hydrangea paniculata ';
        $data->space = $northBed = self::space('North bed');
        $data->diseases = '   ';
        $data->careRecords = [CareTask::Pruning->value => new \DateTimeImmutable('2026-03-10'), CareTask::Watering->value => null];

        $planting = $data->toPlanting(new \DateTimeImmutable('2026-11-21 10:00'), 'photo.png');

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

        $planting = $data->toPlanting(new \DateTimeImmutable('2026-11-21'), null);

        self::assertNull($planting->getScientificName());
        self::assertNull($planting->getTrefleId());
    }

    public function testTheEditFormStartsFromThePlanting(): void
    {
        $northBed = self::space('North bed');
        $planting = new Planting('Hydrangea', $northBed, 'Aphids.', new \DateTimeImmutable('2026-11-01'), 191047, 'Hydrangea macrophylla');
        $planting->recordCare(CareTask::Watering, new \DateTimeImmutable('2026-11-10'));
        $planting->recordCare(CareTask::Watering, new \DateTimeImmutable('2026-11-15'));

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
        $northBed = self::space('North bed');
        $balcony = self::space('Balcony');
        $lavender = new Planting('Lavender', $northBed, null, new \DateTimeImmutable('2026-11-01'));
        $sage = new Planting('Sage', $balcony, null, new \DateTimeImmutable('2026-11-01'));
        $planting = new Planting('Hydrangea', $northBed, null, new \DateTimeImmutable('2026-11-01'));
        $planting->recordCare(CareTask::Watering, new \DateTimeImmutable('2026-11-10'));

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
        $northBed = self::space('North bed');
        $planting = new Planting('Hydrangea', $northBed, null, new \DateTimeImmutable('2026-11-01'));

        $data = PlantingFormData::fromPlanting($planting);
        $data->space = null;
        $data->applyTo($planting);

        self::assertNull($planting->getSpace());
        self::assertSame([], $northBed->getPlantings());
    }

    private static function space(string $name): Space
    {
        return new Space($name, Exposure::Shade, Soil::Clay, new \DateTimeImmutable('2026-11-01'));
    }
}
