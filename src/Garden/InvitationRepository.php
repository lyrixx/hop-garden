<?php

namespace App\Garden;

use App\Account\Entity\User;
use App\Garden\Entity\Garden;
use App\Garden\Entity\Invitation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Invitation>
 */
class InvitationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Invitation::class);
    }

    public function add(Invitation $invitation): void
    {
        $this->getEntityManager()->persist($invitation);
    }

    /**
     * Read again and locked until the current transaction ends: a second click, or another person with the link, waits,
     * then sees the outcome.
     */
    public function lock(Invitation $invitation): void
    {
        $this->getEntityManager()->refresh($invitation, LockMode::PESSIMISTIC_WRITE);
    }

    /**
     * Whatever its status, whatever its garden: the link is all the visitor has.
     */
    public function findOneByToken(#[\SensitiveParameter] string $token): ?Invitation
    {
        return $this->findOneBy(['tokenHash' => Invitation::hash($token)]);
    }

    /**
     * The one invitation of this address that is neither accepted nor revoked, if any.
     */
    public function findOpen(Garden $garden, string $email): ?Invitation
    {
        /** @var ?Invitation $invitation */
        $invitation = $this->createQueryBuilder('invitation')
            ->andWhere('invitation.garden = :garden')
            ->andWhere('invitation.email = :email')
            ->andWhere('invitation.acceptedAt IS NULL')
            ->andWhere('invitation.revokedAt IS NULL')
            ->setParameter('garden', $garden)
            ->setParameter('email', User::normalizeEmail($email))
            ->getQuery()
            ->getOneOrNullResult()
        ;

        return $invitation;
    }

    /**
     * @return list<Invitation> neither accepted nor revoked, expired or not: the newest first
     */
    public function findOpenOfGarden(Garden $garden): array
    {
        /** @var list<Invitation> $invitations */
        $invitations = $this->createQueryBuilder('invitation')
            ->addSelect('inviter')
            ->join('invitation.invitedBy', 'inviter')
            ->andWhere('invitation.garden = :garden')
            ->andWhere('invitation.acceptedAt IS NULL')
            ->andWhere('invitation.revokedAt IS NULL')
            ->setParameter('garden', $garden)
            ->orderBy('invitation.createdAt', \SortDirection::Descending)
            ->addOrderBy('invitation.id', \SortDirection::Descending)
            ->getQuery()
            ->getResult()
        ;

        return $invitations;
    }
}
