<?php

namespace App\Plant;

use App\Garden\Model\Planting;
use App\Plant\Exception\PlantNotFoundException;
use App\Plant\Model\Plant;
use App\Plant\Model\PlantStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Plant>
 */
class PlantRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Plant::class);
    }

    public function add(Plant $plant): void
    {
        $this->getEntityManager()->persist($plant);
    }

    public function get(int $id): Plant
    {
        return $this->find($id) ?? throw PlantNotFoundException::withId($id);
    }

    /**
     * Read again and locked until the current transaction ends: a concurrent request waits, then sees the outcome.
     */
    public function lock(Plant $plant): void
    {
        $this->getEntityManager()->refresh($plant, LockMode::PESSIMISTIC_WRITE);
    }

    /**
     * The drafts proposed in the chat: a draft linked to a planting is reviewed from it.
     *
     * @return list<Plant>
     */
    public function findDraftsWithoutPlanting(): array
    {
        /** @var list<Plant> $plants */
        $plants = $this->createQueryBuilder('p')
            ->andWhere('p.status = :status')
            ->andWhere(\sprintf('NOT EXISTS (SELECT planting.id FROM %s planting WHERE planting.plant = p)', Planting::class))
            ->setParameter('status', PlantStatus::Draft)
            ->orderBy('p.createdAt', \SortDirection::Descending)
            ->getQuery()
            ->getResult()
        ;

        return $plants;
    }

    /**
     * @return list<Plant>
     */
    public function findValidated(): array
    {
        /** @var list<Plant> $plants */
        $plants = $this->createQueryBuilder('p')
            ->addSelect('r')
            ->leftJoin('p.reminders', 'r')
            ->andWhere('p.status = :status')
            ->setParameter('status', PlantStatus::Validated)
            ->orderBy('p.commonName', \SortDirection::Ascending)
            ->getQuery()
            ->getResult()
        ;

        return $plants;
    }

    /**
     * @return list<Plant>
     */
    public function findValidatedBySpecies(string $scientificName): array
    {
        /** @var list<Plant> $plants */
        $plants = $this->createQueryBuilder('p')
            ->andWhere('p.status = :status')
            ->andWhere('LOWER(p.scientificName) = LOWER(:scientificName)')
            ->setParameter('status', PlantStatus::Validated)
            ->setParameter('scientificName', $scientificName)
            ->orderBy('p.commonName', \SortDirection::Ascending)
            ->getQuery()
            ->getResult()
        ;

        return $plants;
    }
}
