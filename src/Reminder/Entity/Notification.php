<?php

namespace App\Reminder\Entity;

use App\Account\Entity\User;
use App\Garden\Entity\Garden;
use App\Reminder\NotificationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A reminder sent to a member of its garden, in their language: one per member.
 */
#[ORM\Entity(repositoryClass: NotificationRepository::class)]
#[ORM\Index(fields: ['recipient', 'garden', 'readAt'])]
class Notification
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $readAt = null;

    /**
     * The garden of its reminder.
     */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Garden $garden;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private User $recipient,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Reminder $reminder,
        #[ORM\Column(type: Types::TEXT)]
        private string $message,
        #[ORM\Column]
        private \DateTimeImmutable $createdAt,
    ) {
        $this->garden = $reminder->getPlant()->getGarden();
        if (!$this->garden->isMember($recipient)) {
            throw new \LogicException(\sprintf('User %d is no member of the garden of reminder %d.', $recipient->getId(), $reminder->getId()));
        }
    }

    public function markRead(\DateTimeImmutable $at): void
    {
        $this->readAt ??= $at;
    }

    public function getId(): int
    {
        return $this->id ?? throw new \LogicException('The notification has not been persisted yet.');
    }

    public function getRecipient(): User
    {
        return $this->recipient;
    }

    public function getGarden(): Garden
    {
        return $this->garden;
    }

    public function getReminder(): Reminder
    {
        return $this->reminder;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getReadAt(): ?\DateTimeImmutable
    {
        return $this->readAt;
    }

    public function isRead(): bool
    {
        return null !== $this->readAt;
    }
}
