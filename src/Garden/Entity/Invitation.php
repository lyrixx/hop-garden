<?php

namespace App\Garden\Entity;

use App\Account\Entity\User;
use App\Garden\InvitationRepository;
use App\Garden\Model\InvitationStatus;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * An address invited into a garden, as a member: the link of the email carries its token, the database only its hash.
 * Whoever has the link may use it, with an account of any address: the owner can send it another way too. One open
 * invitation per address in a garden (neither accepted nor revoked, expired or not): inviting it again renews that one.
 */
#[ORM\Entity(repositoryClass: InvitationRepository::class)]
// As PostgreSQL writes the condition back: a mapping written otherwise would differ from the database forever
#[ORM\UniqueConstraint(fields: ['garden', 'email'], options: ['where' => '((accepted_at IS NULL) AND (revoked_at IS NULL))'])]
class Invitation
{
    /**
     * From the last link: a new one gets 14 days again.
     */
    public const string LIFETIME = '+14 days';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column]
    private string $email;

    // Only the queries read it: InvitationRepository::findOneByToken()
    #[ORM\Column(unique: true)]
    private string $tokenHash; // @phpstan-ignore property.onlyWritten

    #[ORM\Column]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $acceptedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    private function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Garden $garden,
        string $email,
        // Their invitations go with their account
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private User $invitedBy,
        #[\SensitiveParameter]
        string $token,
        #[ORM\Column]
        private \DateTimeImmutable $createdAt,
    ) {
        $this->id = Uuid::v7();
        $this->email = User::normalizeEmail($email);
        $this->tokenHash = self::hash($token);
        $this->expiresAt = $createdAt->modify(self::LIFETIME);
    }

    /**
     * By a member of the garden; App\Garden\Security\GardenVoter says which ones may.
     */
    public static function send(Garden $garden, string $email, User $invitedBy, #[\SensitiveParameter] string $token, \DateTimeImmutable $sentAt): self
    {
        if (!$garden->isMember($invitedBy)) {
            throw new \LogicException(\sprintf('User %s is no member of garden %s: they invite nobody into it.', $invitedBy->getId(), $garden->getId()));
        }

        return new self($garden, $email, $invitedBy, $token, $sentAt);
    }

    /**
     * SHA-256 is enough: the token is random, there is nothing to guess from its hash.
     */
    public static function hash(#[\SensitiveParameter] string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * A new link, sent again or shown again: the one before stops working, and it has 14 days again, expired or not.
     */
    public function renew(#[\SensitiveParameter] string $token, \DateTimeImmutable $at): void
    {
        $this->assertOpen();

        $this->tokenHash = self::hash($token);
        $this->expiresAt = $at->modify(self::LIFETIME);
    }

    /**
     * The garden takes them in, whatever the address of their account.
     */
    public function accept(User $user, \DateTimeImmutable $at): void
    {
        if (!$this->isPending($at)) {
            throw new \LogicException(\sprintf('Invitation %s is %s: nobody joins with it.', $this->id, $this->statusAt($at)->value));
        }

        $this->garden->join($user, $at);
        $this->acceptedAt = $at;
    }

    public function revoke(\DateTimeImmutable $at): void
    {
        $this->assertOpen();

        $this->revokedAt = $at;
    }

    public function statusAt(\DateTimeImmutable $now): InvitationStatus
    {
        return match (true) {
            null !== $this->acceptedAt => InvitationStatus::Accepted,
            null !== $this->revokedAt => InvitationStatus::Revoked,
            $now >= $this->expiresAt => InvitationStatus::Expired,
            default => InvitationStatus::Pending,
        };
    }

    public function isPending(\DateTimeImmutable $now): bool
    {
        return InvitationStatus::Pending === $this->statusAt($now);
    }

    /**
     * Neither accepted nor revoked: an expired one gets a new link.
     */
    public function isOpen(): bool
    {
        return null === $this->acceptedAt && null === $this->revokedAt;
    }

    /**
     * Their account has the address invited: the invitation proves it.
     */
    public function isSentTo(User $user): bool
    {
        return $user->getEmail() === $this->email;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getGarden(): Garden
    {
        return $this->garden;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getInvitedBy(): User
    {
        return $this->invitedBy;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getAcceptedAt(): ?\DateTimeImmutable
    {
        return $this->acceptedAt;
    }

    public function getRevokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }

    private function assertOpen(): void
    {
        if (!$this->isOpen()) {
            throw new \LogicException(\sprintf('Invitation %s is %s already.', $this->id, null !== $this->acceptedAt ? 'accepted' : 'revoked'));
        }
    }
}
