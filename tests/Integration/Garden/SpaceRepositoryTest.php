<?php

namespace App\Tests\Integration\Garden;

use App\Garden\Model\Planting;
use App\Garden\Model\Space;
use App\Garden\SpaceRepository;
use App\Plant\Model\Exposure;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class SpaceRepositoryTest extends KernelTestCase
{
    public function testTheSpacesByNameWithTheirPlantings(): void
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $spaceRepository = self::getContainer()->get(SpaceRepository::class);
        $northBed = new Space('North bed', Exposure::Shade, null, new \DateTimeImmutable('2026-11-01'));
        $spaceRepository->add($northBed);
        $spaceRepository->add(new Space('Balcony', Exposure::Sun, null, new \DateTimeImmutable('2026-11-01')));
        $entityManager->persist(new Planting('Rose', $northBed, null, new \DateTimeImmutable('2026-11-01')));
        $entityManager->persist(new Planting('Hosta', $northBed, null, new \DateTimeImmutable('2026-11-01')));
        $entityManager->persist(new Planting('Sage', null, null, new \DateTimeImmutable('2026-11-01')));
        $entityManager->flush();
        $entityManager->clear();

        $spaces = $spaceRepository->findAllByName();

        self::assertSame(
            ['Balcony' => [], 'North bed' => ['Hosta', 'Rose']],
            array_merge(...array_map(static fn (Space $space): array => [$space->getName() => array_map(static fn (Planting $planting): string => $planting->getName(), $space->getPlantings())], $spaces)),
        );
    }
}
