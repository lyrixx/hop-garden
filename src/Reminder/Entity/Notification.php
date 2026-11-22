<?php

namespace App\Reminder\Entity;

use App\Account\Entity\User;
use App\Garden\Entity\Garden;
use App\Garden\Entity\Invitation;
use App\Reminder\NotificationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * What a member is told in their garden, in their language: a reminder (one per member), or a new member, to who invited
 * them.
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

    private function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private User $recipient,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Garden $garden,
        // One of the two: what it is about
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(onDelete: 'CASCADE')]
        private ?Reminder $reminder,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(onDelete: 'CASCADE')]
        private ?Invitation $invitation,
        #[ORM\Column(type: Types::TEXT)]
        private string $message,
        #[ORM\Column]
        private \DateTimeImmutable $createdAt,
    ) {
        if (!$garden->isMember($recipient)) {
            throw new \LogicException(\sprintf('User %d is no member of garden %d.', $recipient->getId(), $garden->getId()));
        }
    }

    /**
     * In the garden of its reminder.
     */
    public static function remind(User $recipient, Reminder $reminder, string $message, \DateTimeImmutable $createdAt): self
    {
        return new self($recipient, $reminder->getPlant()->getGarden(), $reminder, null, $message, $createdAt);
    }

    /**
     * Somebody joined the garden with this invitation.
     */
    public static function newMember(User $recipient, Invitation $invitation, string $message, \DateTimeImmutable $createdAt): self
    {
        return new self($recipient, $invitation->getGarden(), null, $invitation, $message, $createdAt);
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

    /**
     * Null for a new member.
     */
    public function getReminder(): ?Reminder
    {
        return $this->reminder;
    }

    /**
     * Null for a reminder.
     */
    public function getInvitation(): ?Invitation
    {
        return $this->invitation;
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
