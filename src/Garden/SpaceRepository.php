<?php

namespace App\Garden;

use App\Garden\Model\Space;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
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
    public function findAllByName(): array
    {
        /** @var list<Space> $spaces */
        $spaces = $this->createOrderedQueryBuilder()
            ->addSelect('planting', 'plant')
            ->leftJoin('space.plantings', 'planting')
            ->leftJoin('planting.plant', 'plant')
            ->getQuery()
            ->getResult()
        ;

        return $spaces;
    }

    public function createOrderedQueryBuilder(): QueryBuilder
    {
        return $this->createQueryBuilder('space')->orderBy('space.name', \SortDirection::Ascending);
    }
}
