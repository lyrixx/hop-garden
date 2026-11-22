<?php

namespace App\Account;

use App\Account\Entity\User;
use App\Account\Model\AccessStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Security\User\UserLoaderInterface;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository implements UserLoaderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    public function add(User $user): void
    {
        $this->getEntityManager()->persist($user);
    }

    public function remove(User $user): void
    {
        $this->getEntityManager()->remove($user);
    }

    /**
     * Read again and locked until the current transaction ends: a concurrent request waits, then sees the outcome.
     */
    public function lock(User $user): void
    {
        $this->getEntityManager()->refresh($user, LockMode::PESSIMISTIC_WRITE);
    }

    public function findOneByEmail(string $email): ?User
    {
        return $this->findOneBy(['email' => User::normalizeEmail($email)]);
    }

    /**
     * The login form, and the remember me cookie: the email, whatever its case.
     */
    public function loadUserByIdentifier(string $identifier): ?User
    {
        return $this->findOneByEmail($identifier);
    }

    /**
     * The accounts the administrator has to decide on: their email verified, no decision yet (see User::isWaiting()).
     */
    public function filterWaiting(QueryBuilder $queryBuilder): QueryBuilder
    {
        $alias = $queryBuilder->getRootAliases()[0];

        return $queryBuilder
            ->andWhere("{$alias}.accessStatus = :waiting_status")
            ->andWhere("{$alias}.emailVerifiedAt IS NOT NULL")
            ->setParameter('waiting_status', AccessStatus::Pending)
        ;
    }

    public function countWaiting(): int
    {
        return (int) $this->filterWaiting($this->createQueryBuilder('user'))
            ->select('COUNT(user.id)')
            ->getQuery()
            ->getSingleScalarResult()
        ;
    }
}
