<?php

namespace App\Chat;

use App\Account\Entity\User;
use App\Chat\Entity\Conversation;
use App\Garden\Entity\Garden;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\UuidV7;

/**
 * @extends ServiceEntityRepository<Conversation>
 */
class ConversationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Conversation::class);
    }

    public function add(Conversation $conversation): void
    {
        $this->getEntityManager()->persist($conversation);
    }

    public function findOneById(UuidV7 $id): ?Conversation
    {
        return $this->find($id->toRfc4122());
    }

    /**
     * @return list<UuidV7>
     */
    public function findIdsOfGarden(Garden $garden): array
    {
        return $this->ids(['garden' => $garden]);
    }

    /**
     * @return list<UuidV7>
     */
    public function findIdsOfUser(User $user): array
    {
        return $this->ids(['user' => $user]);
    }

    /**
     * @param array<string, object> $criteria
     *
     * @return list<UuidV7>
     */
    private function ids(array $criteria): array
    {
        $queryBuilder = $this->createQueryBuilder('conversation')->select('conversation.id');
        foreach ($criteria as $field => $value) {
            $queryBuilder->andWhere("conversation.{$field} = :{$field}")->setParameter($field, $value);
        }

        /** @var list<string> $ids */
        $ids = $queryBuilder->getQuery()->getSingleColumnResult();

        return array_map(UuidV7::fromString(...), $ids);
    }
}
