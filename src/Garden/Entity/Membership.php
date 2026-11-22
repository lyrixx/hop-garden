<?php

namespace App\Garden\Entity;

use App\Account\Entity\User;
use App\Garden\Model\MembershipRole;
use Doctrine\ORM\Mapping as ORM;

/**
 * A member of a garden: created and changed by its garden only (Garden::create(), join(), leave(), remove()).
 */
#[ORM\Entity]
#[ORM\UniqueConstraint(fields: ['garden', 'user'])]
class Membership
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * @internal only for Garden
     */
    public function __construct(
        #[ORM\ManyToOne(inversedBy: 'memberships')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Garden $garden,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private User $user,
        #[ORM\Column]
        private MembershipRole $role,
        #[ORM\Column]
        private \DateTimeImmutable $joinedAt,
    ) {
    }

    /**
     * @internal only for Garden: the owner left, the member takes the garden over
     */
    public function takeOver(): void
    {
        $this->role = MembershipRole::Owner;
    }

    public function getId(): int
    {
        return $this->id ?? throw new \LogicException('The membership has not been persisted yet.');
    }

    public function getGarden(): Garden
    {
        return $this->garden;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getRole(): MembershipRole
    {
        return $this->role;
    }

    public function isOwner(): bool
    {
        return MembershipRole::Owner === $this->role;
    }

    public function getJoinedAt(): \DateTimeImmutable
    {
        return $this->joinedAt;
    }
}
