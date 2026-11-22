<?php

namespace App\Chat\Entity;

use App\Account\Entity\User;
use App\Chat\ConversationRepository;
use App\Garden\Entity\Garden;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\UuidV7;

/**
 * Whose a conversation of the gardener agent is: a member, in one of their gardens. Its messages live in the table of
 * the message store of Symfony AI, chat_conversation, under the same id: that table stays the bundle's own.
 */
#[ORM\Entity(repositoryClass: ConversationRepository::class)]
class Conversation
{
    /**
     * The id of its messages in the message store.
     */
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private UuidV7 $id;

    private function __construct(
        UuidV7 $id,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private User $user,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Garden $garden,
        #[ORM\Column]
        private \DateTimeImmutable $startedAt,
    ) {
        $this->id = $id;
    }

    /**
     * In the chat, or to draft the care sheet of a plant they added.
     */
    public static function start(UuidV7 $id, User $user, Garden $garden, \DateTimeImmutable $startedAt): self
    {
        if (!$garden->isMember($user)) {
            throw new \LogicException(\sprintf('User %s is no member of garden %s.', $user->getId(), $garden->getId()));
        }

        return new self($id, $user, $garden, $startedAt);
    }

    public function isOf(User $user, Garden $garden): bool
    {
        return $this->user === $user && $this->garden === $garden;
    }

    public function getId(): UuidV7
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getGarden(): Garden
    {
        return $this->garden;
    }

    public function getStartedAt(): \DateTimeImmutable
    {
        return $this->startedAt;
    }
}
