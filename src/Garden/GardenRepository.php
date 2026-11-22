<?php

namespace App\Garden;

use App\Account\Entity\User;
use App\Garden\Entity\Garden;
use App\Garden\Entity\Membership;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Garden>
 */
class GardenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Garden::class);
    }

    public function add(Garden $garden): void
    {
        $this->getEntityManager()->persist($garden);
    }

    /**
     * Its rows go with it, by the foreign keys of the database: spaces, plants, plantings, reminders, notifications,
     * memberships, conversations. Its photos and the messages of its conversations are not rows of its own: see
     * App\Account\AccountDeletion.
     */
    public function remove(Garden $garden): void
    {
        $this->getEntityManager()->remove($garden);
    }

    /**
     * @return list<Garden> the gardens of this member, the one they joined first first
     */
    public function findOfMember(User $user): array
    {
        /** @var list<Garden> $gardens */
        $gardens = $this->createQueryBuilder('garden')
            ->join(Membership::class, 'membership', 'WITH', 'membership.garden = garden')
            ->andWhere('membership.user = :user')
            ->setParameter('user', $user)
            ->orderBy('membership.joinedAt', \SortDirection::Ascending)
            ->addOrderBy('membership.id', \SortDirection::Ascending)
            ->getQuery()
            ->getResult()
        ;

        return $gardens;
    }

    /**
     * Null when the garden is gone, or when they are no member of it (any more).
     */
    public function findOneOfMember(int $gardenId, User $user): ?Garden
    {
        /** @var ?Garden $garden */
        $garden = $this->createQueryBuilder('garden')
            ->join(Membership::class, 'membership', 'WITH', 'membership.garden = garden')
            ->andWhere('garden.id = :id')
            ->andWhere('membership.user = :user')
            ->setParameter('id', $gardenId)
            ->setParameter('user', $user)
            ->getQuery()
            ->getOneOrNullResult()
        ;

        return $garden;
    }

    /**
     * Every garden with its members, for the reminder check: a garden always has one.
     *
     * @return list<Garden>
     */
    public function findAllWithMembers(): array
    {
        /** @var list<Garden> $gardens */
        $gardens = $this->createQueryBuilder('garden')
            ->addSelect('membership', 'user')
            ->join('garden.memberships', 'membership')
            ->join('membership.user', 'user')
            ->orderBy('garden.id', \SortDirection::Ascending)
            // The order of Garden::$memberships, which this query fills
            ->addOrderBy('membership.joinedAt', \SortDirection::Ascending)
            ->addOrderBy('membership.id', \SortDirection::Ascending)
            ->getQuery()
            ->getResult()
        ;

        return $gardens;
    }
}
