<?php

namespace App\Tests\Integration\Garden;

use App\Factory\PlantingFactory;
use App\Factory\SpaceFactory;
use App\Garden\Entity\Planting;
use App\Garden\Entity\Space;
use App\Garden\SpaceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class SpaceRepositoryTest extends KernelTestCase
{
    public function testTheSpacesByNameWithTheirPlantings(): void
    {
        self::bootKernel();
        $northBed = SpaceFactory::createOne(['name' => 'North bed']);
        SpaceFactory::createOne(['name' => 'Balcony']);
        PlantingFactory::new()->inSpace($northBed)->create(['name' => 'Rose']);
        PlantingFactory::new()->inSpace($northBed)->create(['name' => 'Hosta']);
        PlantingFactory::createOne(['name' => 'Sage']);
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        $spaces = self::getContainer()->get(SpaceRepository::class)->findAllByName();

        self::assertSame(
            ['Balcony' => [], 'North bed' => ['Hosta', 'Rose']],
            array_merge(...array_map(static fn (Space $space): array => [$space->getName() => array_map(static fn (Planting $planting): string => $planting->getName(), $space->getPlantings())], $spaces)),
        );
    }
}
