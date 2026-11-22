<?php

namespace App\Tests\Integration\Garden;

use App\Garden\Model\Planting;
use App\Garden\Model\Space;
use App\Garden\PlantingRepository;
use App\Plant\Model\Exposure;
use App\Reminder\Model\ReminderType;
use App\Tests\Factory\PlantFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PlantingRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private PlantingRepository $plantingRepository;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->plantingRepository = self::getContainer()->get(PlantingRepository::class);
    }

    public function testTheNeighboursAreReadFromTheSpace(): void
    {
        $northBed = new Space('North bed', Exposure::Shade, null, new \DateTimeImmutable('2026-11-01'));
        $this->entityManager->persist($northBed);
        $rose = self::planting('Rose', $northBed);
        $this->save($rose, self::planting('Lavender', $northBed), self::planting('Sage'));
        $this->entityManager->clear();

        $rose = $this->plantingRepository->find($rose->getId()) ?? self::fail();

        self::assertSame('North bed', $rose->getSpace()?->getName());
        self::assertSame(['Lavender'], self::names($rose->getNeighbours()));
    }

    public function testADeletedSpaceLeavesItsPlantingsWithoutASpace(): void
    {
        $northBed = new Space('North bed', Exposure::Shade, null, new \DateTimeImmutable('2026-11-01'));
        $this->entityManager->persist($northBed);
        $rose = self::planting('Rose', $northBed);
        $this->save($rose);

        $this->entityManager->getConnection()->executeStatement('DELETE FROM space WHERE id = ?', [$northBed->getId()]);
        $this->entityManager->clear();

        self::assertNull(($this->plantingRepository->find($rose->getId()) ?? self::fail())->getSpace());
    }

    public function testTheGardenByNameWithTheRemindersOfItsCareSheets(): void
    {
        $rose = PlantFactory::validated('Climbing rose');
        $rose->enableReminder(ReminderType::Pruning);
        $this->entityManager->persist($rose);
        $climbing = Planting::forValidatedPlant($rose);
        $this->save(self::planting('sage'), $climbing, self::planting('Lavender'));
        $this->entityManager->clear();

        $plantings = $this->plantingRepository->findAllByName();

        self::assertSame(['Climbing rose', 'Lavender', 'sage'], self::names($plantings));
        self::assertSame([ReminderType::Pruning], $plantings[0]->getPlant()?->getEnabledReminderTypes());
    }

    public function testWhetherAPlantHasAPlanting(): void
    {
        $plant = PlantFactory::validated();
        $other = PlantFactory::draft('Other');
        $this->entityManager->persist($plant);
        $this->entityManager->persist($other);
        $this->save(Planting::forValidatedPlant($plant));

        self::assertTrue($this->plantingRepository->existsForPlant($plant));
        self::assertFalse($this->plantingRepository->existsForPlant($other));
    }

    private function save(Planting ...$plantings): void
    {
        foreach ($plantings as $planting) {
            $this->plantingRepository->add($planting);
        }
        $this->entityManager->flush();
    }

    private static function planting(string $name, ?Space $space = null): Planting
    {
        return new Planting($name, $space, null, new \DateTimeImmutable('2026-11-01'));
    }

    /**
     * @param list<Planting> $plantings
     *
     * @return list<string>
     */
    private static function names(array $plantings): array
    {
        return array_map(static fn (Planting $planting): string => $planting->getName(), $plantings);
    }
}
