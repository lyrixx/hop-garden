<?php

namespace App\Tests\Integration\Garden;

use App\Factory\PlantFactory;
use App\Factory\PlantingFactory;
use App\Factory\SpaceFactory;
use App\Garden\Entity\Garden;
use App\Garden\Entity\Planting;
use App\Garden\PlantingRepository;
use App\Reminder\Model\ReminderType;
use App\Story\DefaultGardenStory;
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
        $northBed = SpaceFactory::createOne(['name' => 'North bed']);
        $rose = PlantingFactory::new()->inSpace($northBed)->create(['name' => 'Rose']);
        PlantingFactory::new()->inSpace($northBed)->create(['name' => 'Lavender']);
        PlantingFactory::createOne(['name' => 'Sage']);
        $this->entityManager->clear();

        $rose = $this->plantingRepository->find($rose->getId()) ?? self::fail();

        self::assertSame('North bed', $rose->getSpace()?->getName());
        self::assertSame(['Lavender'], self::names($rose->getNeighbours()));
    }

    public function testADeletedSpaceLeavesItsPlantingsWithoutASpace(): void
    {
        $northBed = SpaceFactory::createOne();
        $rose = PlantingFactory::new()->inSpace($northBed)->create();

        $this->entityManager->getConnection()->executeStatement('DELETE FROM space WHERE id = ?', [$northBed->getId()]);
        $this->entityManager->clear();

        self::assertNull(($this->plantingRepository->find($rose->getId()) ?? self::fail())->getSpace());
    }

    public function testTheGardenByNameWithTheRemindersOfItsCareSheets(): void
    {
        $rose = PlantFactory::new()->validated()->withEnabledReminders(ReminderType::Pruning)->create(['commonName' => 'Climbing rose']);
        PlantingFactory::createOne(['name' => 'sage']);
        PlantingFactory::new()->of($rose)->create();
        PlantingFactory::createOne(['name' => 'Lavender']);
        $this->entityManager->clear();

        $plantings = $this->plantingRepository->findAllByName(self::garden());

        self::assertSame(['Climbing rose', 'Lavender', 'sage'], self::names($plantings));
        self::assertSame([ReminderType::Pruning], $plantings[0]->getPlant()?->getEnabledReminderTypes());
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

    private static function garden(): Garden
    {
        $garden = DefaultGardenStory::get('garden');
        self::assertInstanceOf(Garden::class, $garden);

        return $garden;
    }
}
