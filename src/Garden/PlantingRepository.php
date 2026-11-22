<?php

namespace App\Garden;

use App\Garden\Model\Planting;
use App\Plant\Model\Plant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Planting>
 */
class PlantingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Planting::class);
    }

    public function add(Planting $planting): void
    {
        $this->getEntityManager()->persist($planting);
    }

    /**
     * With their care sheet and its reminders, for the list of the garden.
     *
     * @return list<Planting>
     */
    public function findAllByName(): array
    {
        /** @var list<Planting> $plantings */
        $plantings = $this->createOrderedQueryBuilder()
            ->addSelect('p', 'r')
            ->leftJoin('planting.plant', 'p')
            ->leftJoin('p.reminders', 'r')
            ->getQuery()
            ->getResult()
        ;

        return $plantings;
    }

    public function existsForPlant(Plant $plant): bool
    {
        return null !== $this->createQueryBuilder('planting')
            ->select('1')
            ->andWhere('planting.plant = :plant')
            ->setParameter('plant', $plant)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult()
        ;
    }

    /**
     * @param list<Plant> $plants
     *
     * @return array<int, list<int>> the ids of the plantings of each plant that has some, oldest first, by plant id
     */
    public function findIdsForPlants(array $plants): array
    {
        if ([] === $plants) {
            return [];
        }

        /** @var list<array{plantId: int|string, plantingId: int|string}> $rows */
        $rows = $this->createQueryBuilder('planting')
            ->select('IDENTITY(planting.plant) AS plantId', 'planting.id AS plantingId')
            ->andWhere('planting.plant IN (:plants)')
            ->setParameter('plants', $plants)
            ->orderBy('planting.id', \SortDirection::Ascending)
            ->getQuery()
            ->getArrayResult()
        ;

        $ids = [];
        foreach ($rows as $row) {
            $ids[(int) $row['plantId']][] = (int) $row['plantingId'];
        }

        return $ids;
    }

    /**
     * The plants of the garden following this care sheet, and so its reminders, with their space.
     *
     * @return list<Planting>
     */
    public function findByPlant(Plant $plant): array
    {
        /** @var list<Planting> $plantings */
        $plantings = $this->createOrderedQueryBuilder()
            ->leftJoin('planting.space', 'space')
            ->addSelect('space')
            ->andWhere('planting.plant = :plant')
            ->setParameter('plant', $plant)
            ->getQuery()
            ->getResult()
        ;

        return $plantings;
    }

    private function createOrderedQueryBuilder(): QueryBuilder
    {
        return $this->createQueryBuilder('planting')->orderBy('planting.name', \SortDirection::Ascending);
    }
}
