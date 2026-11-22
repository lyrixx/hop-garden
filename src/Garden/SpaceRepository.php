<?php

namespace App\Garden;

use App\Garden\Entity\Garden;
use App\Garden\Entity\Space;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * The queries take the garden: a space of another garden is never found. A space found by id is checked by
 * App\Garden\Security\GardenResourceVoter.
 *
 * @extends ServiceEntityRepository<Space>
 */
class SpaceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Space::class);
    }

    public function add(Space $space): void
    {
        $this->getEntityManager()->persist($space);
    }

    /**
     * With their plantings and the care sheets of these, for the list of the spaces.
     *
     * @return list<Space>
     */
    public function findAllByName(Garden $garden): array
    {
        /** @var list<Space> $spaces */
        $spaces = $this->createOrderedQueryBuilder($garden)
            ->addSelect('planting', 'plant')
            ->leftJoin('space.plantings', 'planting')
            ->leftJoin('planting.plant', 'plant')
            ->getQuery()
            ->getResult()
        ;

        return $spaces;
    }

    /**
     * The same name, whatever its case.
     */
    public function findOneByName(Garden $garden, string $name): ?Space
    {
        /** @var ?Space $space */
        $space = $this->createQueryBuilder('space')
            ->andWhere('space.garden = :garden')
            ->andWhere('LOWER(space.name) = LOWER(:name)')
            ->setParameter('garden', $garden)
            ->setParameter('name', $name)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult()
        ;

        return $space;
    }

    /**
     * Null when this garden has no space of this id, whether another one has it or not.
     */
    public function findOneInGarden(Garden $garden, Uuid $id): ?Space
    {
        return $this->findOneBy(['garden' => $garden, 'id' => $id]);
    }

    /**
     * Whatever its garden: a proposal id is unique. The caller checks the space is of the current one.
     */
    public function findOneByProposalId(Uuid $proposalId): ?Space
    {
        return $this->findOneBy(['proposalId' => $proposalId]);
    }

    public function createOrderedQueryBuilder(Garden $garden): QueryBuilder
    {
        return $this->createQueryBuilder('space')
            ->andWhere('space.garden = :garden')
            ->setParameter('garden', $garden)
            ->orderBy('space.name', \SortDirection::Ascending)
        ;
    }
}
