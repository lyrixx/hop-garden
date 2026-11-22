<?php

namespace App\Plant;

use App\Garden\Entity\Garden;
use App\Garden\Entity\Planting;
use App\Plant\Entity\Plant;
use App\Plant\Exception\PlantNotFoundException;
use App\Plant\Model\PlantStatus;
use App\Plant\Model\PlantWithPlantings;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * The care sheets of the species of a garden: the queries take the garden, a care sheet of another one is never found.
 * One found by id is checked by App\Garden\Security\GardenResourceVoter.
 *
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

    /**
     * An id as a model gives it: malformed, of another garden or unknown, the same answer. A care sheet of another garden
     * is not said to exist.
     */
    public function get(Garden $garden, string $id): Plant
    {
        $plant = Uuid::isValid($id) ? $this->findOneInGarden($garden, Uuid::fromString($id)) : null;

        return $plant ?? throw PlantNotFoundException::withId($id);
    }

    public function findOneInGarden(Garden $garden, Uuid $id): ?Plant
    {
        return $this->findOneBy(['garden' => $garden, 'id' => $id]);
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
    public function findDraftsWithoutPlanting(Garden $garden): array
    {
        /** @var list<Plant> $plants */
        $plants = $this->createQueryBuilder('p')
            ->andWhere('p.garden = :garden')
            ->andWhere('p.status = :status')
            ->setParameter('garden', $garden)
            ->andWhere(\sprintf('NOT EXISTS (SELECT planting.id FROM %s planting WHERE planting.plant = p)', Planting::class))
            ->setParameter('status', PlantStatus::Draft)
            ->orderBy('p.createdAt', \SortDirection::Descending)
            ->getQuery()
            ->getResult()
        ;

        return $plants;
    }

    /**
     * Every species, the validated ones first, each with my plants of it and their space: one query for the index.
     *
     * @return list<PlantWithPlantings>
     */
    public function findAllWithPlantings(Garden $garden): array
    {
        /** @var list<Plant|Planting|null> $entities */
        $entities = $this->createQueryBuilder('p')
            ->andWhere('p.garden = :garden')
            ->setParameter('garden', $garden)
            ->addSelect('planting', 'space')
            // Planting holds the relation, and Plant has no inverse side to keep in sync
            ->leftJoin(Planting::class, 'planting', Join::WITH, 'planting.plant = p')
            ->leftJoin('planting.space', 'space')
            ->addSelect('CASE WHEN p.status = :validated THEN 0 ELSE 1 END AS HIDDEN statusOrder')
            ->setParameter('validated', PlantStatus::Validated)
            ->orderBy('statusOrder', \SortDirection::Ascending)
            ->addOrderBy('p.commonName', \SortDirection::Ascending)
            ->addOrderBy('p.id', \SortDirection::Ascending)
            ->addOrderBy('planting.name', \SortDirection::Ascending)
            ->getQuery()
            ->getResult()
        ;

        // Both entities come in one flat list, with a null for a species without planting
        $plants = [];
        $plantingsByPlant = [];
        foreach ($entities as $entity) {
            if ($entity instanceof Plant) {
                $plants[$entity->getId()->toRfc4122()] = $entity;
            } elseif ($entity instanceof Planting) {
                $plant = $entity->getPlant() ?? throw new \LogicException('A planting joined on its plant has one.');
                $plantingsByPlant[$plant->getId()->toRfc4122()][] = $entity;
            }
        }

        return array_values(array_map(
            static fn (Plant $plant): PlantWithPlantings => new PlantWithPlantings($plant, $plantingsByPlant[$plant->getId()->toRfc4122()] ?? []),
            $plants,
        ));
    }

    /**
     * The care sheet already made for this species, validated or still a draft: a draft of the chat may have no Trefle
     * id, so the scientific name counts too.
     */
    public function findOneBySpecies(Garden $garden, int $trefleId, string $scientificName): ?Plant
    {
        /** @var ?Plant $plant */
        $plant = $this->createQueryBuilder('p')
            ->andWhere('p.garden = :garden')
            ->andWhere('p.trefleId = :trefleId OR LOWER(p.scientificName) = LOWER(:scientificName)')
            ->setParameter('garden', $garden)
            ->addSelect('CASE WHEN p.status = :validated THEN 0 ELSE 1 END AS HIDDEN statusOrder')
            ->setParameter('trefleId', $trefleId)
            ->setParameter('scientificName', $scientificName)
            ->setParameter('validated', PlantStatus::Validated)
            ->orderBy('statusOrder', \SortDirection::Ascending)
            ->addOrderBy('p.createdAt', \SortDirection::Ascending)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult()
        ;

        return $plant;
    }

    /**
     * @return list<Plant>
     */
    public function findValidated(Garden $garden): array
    {
        /** @var list<Plant> $plants */
        $plants = $this->createQueryBuilder('p')
            ->addSelect('r')
            ->leftJoin('p.reminders', 'r')
            ->andWhere('p.garden = :garden')
            ->andWhere('p.status = :status')
            ->setParameter('garden', $garden)
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
    public function findValidatedBySpecies(Garden $garden, string $scientificName): array
    {
        /** @var list<Plant> $plants */
        $plants = $this->createQueryBuilder('p')
            ->andWhere('p.garden = :garden')
            ->andWhere('p.status = :status')
            ->setParameter('garden', $garden)
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
