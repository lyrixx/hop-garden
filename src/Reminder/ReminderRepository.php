<?php

namespace App\Reminder;

use App\Garden\Entity\Garden;
use App\Plant\Model\PlantStatus;
use App\Reminder\Entity\Reminder;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Reminder>
 */
class ReminderRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Reminder::class);
    }

    /**
     * @return list<Reminder>
     */
    public function findEnabled(Garden $garden): array
    {
        /** @var list<Reminder> $reminders */
        $reminders = $this->createQueryBuilder('r')
            ->addSelect('p')
            ->join('r.plant', 'p')
            ->andWhere('p.garden = :garden')
            ->andWhere('r.enabled = true')
            ->setParameter('garden', $garden)
            ->andWhere('p.status = :status')
            ->setParameter('status', PlantStatus::Validated)
            ->orderBy('p.commonName', \SortDirection::Ascending)
            ->addOrderBy('r.type', \SortDirection::Ascending)
            ->getQuery()
            ->getResult()
        ;

        return $reminders;
    }
}
