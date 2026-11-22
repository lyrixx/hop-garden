<?php

namespace App\Tests\Integration\Plant;

use App\Garden\Entity\Planting;
use App\Garden\Entity\Space;
use App\Plant\Entity\Plant;
use App\Plant\Exception\PlantNotFoundException;
use App\Plant\Model\PlantWithPlantings;
use App\Plant\PlantRepository;
use App\Reminder\Model\ReminderType;
use App\Tests\Utils\Factory\PlantFactory;
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

    public function testEverySpeciesValidatedFirstWithMyPlantsAndTheirSpace(): void
    {
        $rose = PlantFactory::validated('Rose');
        $lavender = PlantFactory::validated('Lavender');
        $box = PlantFactory::draft('Box');
        $alone = PlantFactory::validated('Alone');
        $this->save($rose, $box, $lavender, $alone);
        $northBed = new Space('North bed', null, null, new \DateTimeImmutable('2026-11-01'));
        $this->entityManager->persist($northBed);
        foreach ([['Rose on the trellis', $rose, $northBed], ['Rose by the gate', $rose, null], ['Box by the door', $box, null], ['Lavender pot', $lavender, null]] as [$name, $plant, $space]) {
            $planting = new Planting($name, $space, null, new \DateTimeImmutable('2026-11-01'));
            $planting->linkTo($plant);
            $this->entityManager->persist($planting);
        }
        $this->entityManager->persist(new Planting('Without care sheet', null, null, new \DateTimeImmutable('2026-11-01')));
        $this->entityManager->flush();
        $this->entityManager->clear();

        $entries = $this->plantRepository->findAllWithPlantings();

        self::assertSame(
            [
                'Alone' => [],
                'Lavender' => ['Lavender pot'],
                'Rose' => ['Rose by the gate', 'Rose on the trellis'],
                'Box' => ['Box by the door'],
            ],
            array_merge(...array_map(static fn (PlantWithPlantings $entry): array => [
                $entry->plant->getCommonName() => array_map(static fn (Planting $planting): string => $planting->getName(), $entry->plantings),
            ], $entries)),
            'The validated species by name, then the drafts.',
        );
        self::assertSame('North bed', $entries[2]->plantings[1]->getSpace()?->getName());
        self::assertTrue($this->entityManager->getUnitOfWork()->isInIdentityMap($entries[2]->plantings[1]->getSpace() ?? self::fail()), 'Loaded with the plantings.');
    }

    public function testTheCareSheetOfASpeciesByItsTrefleIdOrItsName(): void
    {
        $draft = Plant::propose('Draft hydrangea', 'Hydrangea paniculata', 'Hydrangeaceae', PlantFactory::careSheet(), new \DateTimeImmutable('2026-11-01'));
        $validated = Plant::propose('Panicled hydrangea', 'Hydrangea paniculata', 'Hydrangeaceae', PlantFactory::careSheet(), new \DateTimeImmutable('2026-11-02'), 191052);
        $validated->validate(new \DateTimeImmutable('2026-11-02'));
        $this->save($draft, $validated, PlantFactory::draft());

        self::assertSame('Panicled hydrangea', $this->plantRepository->findOneBySpecies(191052, 'Hydrangea paniculata')?->getCommonName(), 'The validated one first.');
        self::assertSame('Bigleaf hydrangea', $this->plantRepository->findOneBySpecies(191047, 'HYDRANGEA macrophylla')?->getCommonName(), 'A draft without Trefle id, by its name.');
        self::assertSame('Panicled hydrangea', $this->plantRepository->findOneBySpecies(191052, 'Another name')?->getCommonName(), 'By its Trefle id.');
        self::assertNull($this->plantRepository->findOneBySpecies(263311, 'Rosa canina'));
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
