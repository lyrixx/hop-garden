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
        return $this->find($id);
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
     * @return list<UuidV7>
     */
    public function findIdsOf(User $user, Garden $garden): array
    {
        return $this->ids(['user' => $user, 'garden' => $garden]);
    }

    /**
     * Their rows only: the messages live in the table of the message store, under the same ids (findIdsOf()).
     */
    public function deleteOf(User $user, Garden $garden): void
    {
        $this->createQueryBuilder('conversation')
            ->delete()
            ->andWhere('conversation.user = :user')
            ->andWhere('conversation.garden = :garden')
            ->setParameter('user', $user)
            ->setParameter('garden', $garden)
            ->getQuery()
            ->execute()
        ;
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

        /** @var list<string> $ids the values of the column, as PostgreSQL gives them */
        $ids = $queryBuilder->getQuery()->getSingleColumnResult();

        return array_map(UuidV7::fromString(...), $ids);
    }
}
