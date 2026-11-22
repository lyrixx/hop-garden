<?php

namespace App\Garden;

use App\Garden\Entity\Garden;
use App\Garden\Entity\Planting;
use App\Plant\Entity\Plant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * The queries of a list take the garden: a planting of another garden is never listed. A planting found by id is checked
 * by App\Garden\Security\GardenResourceVoter.
 *
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
    public function findAllByName(Garden $garden): array
    {
        /** @var list<Planting> $plantings */
        $plantings = $this->createOrderedQueryBuilder($garden)
            ->addSelect('p', 'r')
            ->leftJoin('planting.plant', 'p')
            ->leftJoin('p.reminders', 'r')
            ->getQuery()
            ->getResult()
        ;

        return $plantings;
    }

    /**
     * Whatever its garden: a proposal id is unique. The caller checks the planting is of the current one.
     */
    public function findOneByProposalId(string $proposalId): ?Planting
    {
        return $this->findOneBy(['proposalId' => $proposalId]);
    }

    /**
     * @param list<Plant> $plants of this garden
     *
     * @return array<int, list<int>> the ids of the plantings of each plant that has some, oldest first, by plant id
     */
    public function findIdsForPlants(Garden $garden, array $plants): array
    {
        if ([] === $plants) {
            return [];
        }

        /** @var list<array{plantId: int|string, plantingId: int|string}> $rows */
        $rows = $this->createQueryBuilder('planting')
            ->select('IDENTITY(planting.plant) AS plantId', 'planting.id AS plantingId')
            ->andWhere('planting.garden = :garden')
            ->andWhere('planting.plant IN (:plants)')
            ->setParameter('garden', $garden)
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
     * The photos of a garden, removed with it.
     *
     * @return list<string> their file names
     */
    public function findPhotoFilenames(Garden $garden): array
    {
        /** @var list<string> $filenames */
        $filenames = $this->createQueryBuilder('planting')
            ->select('planting.photoFilename')
            ->andWhere('planting.garden = :garden')
            ->andWhere('planting.photoFilename IS NOT NULL')
            ->setParameter('garden', $garden)
            ->getQuery()
            ->getSingleColumnResult()
        ;

        return $filenames;
    }

    /**
     * The plants of the garden following this care sheet, and so its reminders, with their space.
     *
     * @return list<Planting>
     */
    public function findByPlant(Plant $plant): array
    {
        /** @var list<Planting> $plantings */
        $plantings = $this->createOrderedQueryBuilder($plant->getGarden())
            ->leftJoin('planting.space', 'space')
            ->addSelect('space')
            ->andWhere('planting.plant = :plant')
            ->setParameter('plant', $plant)
            ->getQuery()
            ->getResult()
        ;

        return $plantings;
    }

    private function createOrderedQueryBuilder(Garden $garden): QueryBuilder
    {
        return $this->createQueryBuilder('planting')
            ->andWhere('planting.garden = :garden')
            ->setParameter('garden', $garden)
            ->orderBy('planting.name', \SortDirection::Ascending)
        ;
    }
}
