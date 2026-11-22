<?php

namespace App\Tests\Integration\Plant;

use App\Factory\PlantFactory;
use App\Factory\PlantingFactory;
use App\Factory\SpaceFactory;
use App\Garden\Entity\Planting;
use App\Plant\Entity\Plant;
use App\Plant\Exception\PlantNotFoundException;
use App\Plant\Model\PlantWithPlantings;
use App\Plant\PlantRepository;
use App\Reminder\Model\ReminderType;
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
        PlantFactory::createOne(['commonName' => 'Older draft', 'createdAt' => new \DateTimeImmutable('2026-11-01 10:00')]);
        PlantFactory::createOne(['commonName' => 'Newer draft', 'createdAt' => new \DateTimeImmutable('2026-11-02 10:00')]);
        PlantingFactory::new()->linkedTo(PlantFactory::new(['commonName' => 'Draft of a planting']))->create();
        PlantFactory::new()->validated()->create(['commonName' => 'Validated']);

        self::assertSame(['Newer draft', 'Older draft'], self::names($this->plantRepository->findDraftsWithoutPlanting()));
    }

    public function testTheValidatedPlantsByNameWithTheirReminders(): void
    {
        PlantFactory::new()->validated()->withEnabledReminders(ReminderType::Cutting)->create(['commonName' => 'Sage']);
        PlantFactory::new()->validated()->create(['commonName' => 'Lavender']);
        PlantFactory::createOne(['commonName' => 'Box']);
        $this->entityManager->clear();

        $plants = $this->plantRepository->findValidated();

        self::assertSame(['Lavender', 'Sage'], self::names($plants));
        self::assertSame([ReminderType::Cutting], $plants[1]->getEnabledReminderTypes());
    }

    public function testTheValidatedPlantsOfASpeciesWhateverItsCase(): void
    {
        $macrophylla = PlantFactory::new(['scientificName' => 'Hydrangea macrophylla']);
        $macrophylla->validated()->create(['commonName' => 'Bigleaf hydrangea']);
        $macrophylla->validated()->create(['commonName' => 'Hortensia']);
        $macrophylla->create(['commonName' => 'Draft hydrangea']);
        PlantFactory::createOne(['commonName' => 'Panicled hydrangea', 'scientificName' => 'Hydrangea paniculata']);

        self::assertSame(['Bigleaf hydrangea', 'Hortensia'], self::names($this->plantRepository->findValidatedBySpecies('hydrangea MACROPHYLLA')));
        self::assertSame([], $this->plantRepository->findValidatedBySpecies('Hydrangea paniculata'), 'A draft only.');
        self::assertSame([], $this->plantRepository->findValidatedBySpecies('Hydrangea'), 'The species, not the genus.');
    }

    public function testEverySpeciesValidatedFirstWithMyPlantsAndTheirSpace(): void
    {
        $rose = PlantFactory::new()->validated()->create(['commonName' => 'Rose']);
        $lavender = PlantFactory::new()->validated()->create(['commonName' => 'Lavender']);
        $box = PlantFactory::createOne(['commonName' => 'Box']);
        PlantFactory::new()->validated()->create(['commonName' => 'Alone']);
        $northBed = SpaceFactory::createOne(['name' => 'North bed']);
        PlantingFactory::new()->inSpace($northBed)->linkedTo($rose)->create(['name' => 'Rose on the trellis']);
        PlantingFactory::new()->linkedTo($rose)->create(['name' => 'Rose by the gate']);
        PlantingFactory::new()->linkedTo($box)->create(['name' => 'Box by the door']);
        PlantingFactory::new()->linkedTo($lavender)->create(['name' => 'Lavender pot']);
        PlantingFactory::createOne(['name' => 'Without care sheet']);
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
        PlantFactory::createOne(['commonName' => 'Draft hydrangea', 'scientificName' => 'Hydrangea paniculata']);
        PlantFactory::new()->validated()->create(['commonName' => 'Panicled hydrangea', 'scientificName' => 'Hydrangea paniculata', 'createdAt' => new \DateTimeImmutable('2026-11-02'), 'trefleId' => 191052]);
        PlantFactory::createOne();

        self::assertSame('Panicled hydrangea', $this->plantRepository->findOneBySpecies(191052, 'Hydrangea paniculata')?->getCommonName(), 'The validated one first.');
        self::assertSame('Bigleaf hydrangea', $this->plantRepository->findOneBySpecies(191047, 'HYDRANGEA macrophylla')?->getCommonName(), 'A draft without Trefle id, by its name.');
        self::assertSame('Panicled hydrangea', $this->plantRepository->findOneBySpecies(191052, 'Another name')?->getCommonName(), 'By its Trefle id.');
        self::assertNull($this->plantRepository->findOneBySpecies(263311, 'Rosa canina'));
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
