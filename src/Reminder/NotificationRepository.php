<?php

namespace App\Reminder;

use App\Account\Entity\User;
use App\Garden\Entity\Garden;
use App\Reminder\Entity\Notification;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * The notifications of a member, in a garden: each member gets their own.
 *
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
    public function findLatest(User $recipient, Garden $garden, int $limit = 50): array
    {
        /** @var list<Notification> $notifications */
        $notifications = $this->createQueryBuilder('n')
            ->addSelect('r', 'p')
            ->join('n.reminder', 'r')
            ->join('r.plant', 'p')
            ->andWhere('n.recipient = :recipient')
            ->andWhere('n.garden = :garden')
            ->setParameter('recipient', $recipient)
            ->setParameter('garden', $garden)
            ->orderBy('n.createdAt', \SortDirection::Descending)
            ->addOrderBy('n.id', \SortDirection::Descending)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult()
        ;

        return $notifications;
    }

    public function countUnread(User $recipient, Garden $garden): int
    {
        return (int) $this->createQueryBuilder('n')
            ->select('COUNT(n.id)')
            ->andWhere('n.recipient = :recipient')
            ->andWhere('n.garden = :garden')
            ->andWhere('n.readAt IS NULL')
            ->setParameter('recipient', $recipient)
            ->setParameter('garden', $garden)
            ->getQuery()
            ->getSingleScalarResult()
        ;
    }

    /**
     * @return list<Notification>
     */
    public function findUnread(User $recipient, Garden $garden): array
    {
        /** @var list<Notification> $notifications */
        $notifications = $this->createQueryBuilder('n')
            ->andWhere('n.recipient = :recipient')
            ->andWhere('n.garden = :garden')
            ->andWhere('n.readAt IS NULL')
            ->setParameter('recipient', $recipient)
            ->setParameter('garden', $garden)
            ->getQuery()
            ->getResult()
        ;

        return $notifications;
    }
}
