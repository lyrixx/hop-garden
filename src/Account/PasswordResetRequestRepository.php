<?php

namespace App\Account;

use App\Account\Entity\PasswordResetRequest;
use App\Account\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PasswordResetRequest>
 */
class PasswordResetRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PasswordResetRequest::class);
    }

    public function add(PasswordResetRequest $request): void
    {
        $this->getEntityManager()->persist($request);
    }

    public function findOneByToken(#[\SensitiveParameter] string $token): ?PasswordResetRequest
    {
        return $this->findOneBy(['tokenHash' => PasswordResetRequest::hash($token)]);
    }

    /**
     * On flush: a new request replaces the older ones, a new password ends them all.
     */
    public function removeAllOf(User $user): void
    {
        foreach ($this->findBy(['user' => $user]) as $request) {
            $this->getEntityManager()->remove($request);
        }
    }
}
