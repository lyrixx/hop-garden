<?php

namespace App\Reminder;

use App\Reminder\Entity\Notification;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Notification>
 */
class NotificationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Notification::class);
    }

    public function add(Notification $notification): void
    {
        $this->getEntityManager()->persist($notification);
    }

    /**
     * @return list<Notification>
     */
    public function findLatest(int $limit = 50): array
    {
        /** @var list<Notification> $notifications */
        $notifications = $this->createQueryBuilder('n')
            ->addSelect('r', 'p')
            ->join('n.reminder', 'r')
            ->join('r.plant', 'p')
            ->orderBy('n.createdAt', \SortDirection::Descending)
            ->addOrderBy('n.id', \SortDirection::Descending)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult()
        ;

        return $notifications;
    }

    public function countUnread(): int
    {
        return (int) $this->createQueryBuilder('n')
            ->select('COUNT(n.id)')
            ->andWhere('n.readAt IS NULL')
            ->getQuery()
            ->getSingleScalarResult()
        ;
    }

    /**
     * @return list<Notification>
     */
    public function findUnread(): array
    {
        /** @var list<Notification> $notifications */
        $notifications = $this->createQueryBuilder('n')
            ->andWhere('n.readAt IS NULL')
            ->getQuery()
            ->getResult()
        ;

        return $notifications;
    }
}
