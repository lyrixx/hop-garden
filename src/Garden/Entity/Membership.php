<?php

namespace App\Garden\Entity;

use App\Account\Entity\User;
use App\Garden\Model\MembershipRole;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * A member of a garden: created and changed by its garden only (Garden::create(), join(), leave(), remove()).
 */
#[ORM\Entity]
#[ORM\UniqueConstraint(fields: ['garden', 'user'])]
class Membership
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

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
        $this->id = Uuid::v7();
    }

    /**
     * @internal only for Garden: the owner left, the member takes the garden over
     */
    public function takeOver(): void
    {
        $this->role = MembershipRole::Owner;
    }

    public function getId(): Uuid
    {
        return $this->id;
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
