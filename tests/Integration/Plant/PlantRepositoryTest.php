<?php

namespace App\Tests\Integration\Plant;

use App\Garden\Model\Planting;
use App\Plant\Exception\PlantNotFoundException;
use App\Plant\Model\Plant;
use App\Plant\PlantRepository;
use App\Reminder\Model\ReminderType;
use App\Tests\Factory\PlantFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PlantRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private PlantRepository $plantRepository;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->plantRepository = self::getContainer()->get(PlantRepository::class);
    }

    public function testAnUnknownPlant(): void
    {
        $this->expectException(PlantNotFoundException::class);
        $this->expectExceptionMessageIs('No plant with id 999999.');

        $this->plantRepository->get(999999);
    }

    public function testTheDraftsOfTheChatNewestFirst(): void
    {
        $older = Plant::propose('Older draft', 'Rosa canina', 'Rosaceae', PlantFactory::careSheet(), new \DateTimeImmutable('2026-11-01 10:00'));
        $newer = Plant::propose('Newer draft', 'Rosa gallica', 'Rosaceae', PlantFactory::careSheet(), new \DateTimeImmutable('2026-11-02 10:00'));
        $ofAPlanting = PlantFactory::draft('Draft of a planting');
        $planting = new Planting('Hydrangea by the shed', null, null, new \DateTimeImmutable('2026-11-01'));
        $planting->linkTo($ofAPlanting);
        $this->save($older, $newer, $ofAPlanting, PlantFactory::validated('Validated'));
        $this->entityManager->persist($planting);
        $this->entityManager->flush();

        self::assertSame(['Newer draft', 'Older draft'], self::names($this->plantRepository->findDraftsWithoutPlanting()));
    }

    public function testTheValidatedPlantsByNameWithTheirReminders(): void
    {
        $sage = PlantFactory::validated('Sage');
        $sage->enableReminder(ReminderType::Cutting);
        $this->save($sage, PlantFactory::validated('Lavender'), PlantFactory::draft('Box'));
        $this->entityManager->clear();

        $plants = $this->plantRepository->findValidated();

        self::assertSame(['Lavender', 'Sage'], self::names($plants));
        self::assertSame([ReminderType::Cutting], $plants[1]->getEnabledReminderTypes());
    }

    public function testTheValidatedPlantsOfASpeciesWhateverItsCase(): void
    {
        $this->save(
            PlantFactory::validated('Bigleaf hydrangea'),
            PlantFactory::validated('Hortensia'),
            PlantFactory::draft('Draft hydrangea'),
            Plant::propose('Panicled hydrangea', 'Hydrangea paniculata', 'Hydrangeaceae', PlantFactory::careSheet(), new \DateTimeImmutable('2026-11-01')),
        );

        self::assertSame(['Bigleaf hydrangea', 'Hortensia'], self::names($this->plantRepository->findValidatedBySpecies('hydrangea MACROPHYLLA')));
        self::assertSame([], $this->plantRepository->findValidatedBySpecies('Hydrangea paniculata'), 'A draft only.');
        self::assertSame([], $this->plantRepository->findValidatedBySpecies('Hydrangea'), 'The species, not the genus.');
    }

    private function save(Plant ...$plants): void
    {
        foreach ($plants as $plant) {
            $this->plantRepository->add($plant);
        }
        $this->entityManager->flush();
    }

    /**
     * @param list<Plant> $plants
     *
     * @return list<string>
     */
    private static function names(array $plants): array
    {
        return array_map(static fn (Plant $plant): string => $plant->getCommonName(), $plants);
    }
}
