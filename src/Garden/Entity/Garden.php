<?php

namespace App\Garden\Entity;

use App\Account\Entity\User;
use App\Garden\GardenRepository;
use App\Garden\Model\MembershipRole;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * The tenant: a garden and its members, who share its care sheets, its spaces, its plants and its reminders. Nobody
 * else sees any of them (App\Garden\Security\GardenVoter, App\Garden\Security\GardenResourceVoter). It always has one
 * owner.
 */
#[ORM\Entity(repositoryClass: GardenRepository::class)]
class Garden
{
    /**
     * The reminders are checked every day from 7:00, in the timezone of the garden.
     */
    public const int REMINDER_CHECK_HOUR = 7;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    /**
     * The oldest first.
     *
     * @var Collection<int, Membership>
     */
    #[ORM\OneToMany(targetEntity: Membership::class, mappedBy: 'garden', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['joinedAt' => 'ASC', 'id' => 'ASC'])]
    private Collection $memberships;

    /**
     * The day of the last reminder check, in the timezone of the garden: one a day.
     */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $remindersCheckedOn = null;

    private function __construct(
        #[ORM\Column]
        private string $name,
        /**
         * The name of its URL, unique in the whole site: see App\Garden\Slugs. A renaming keeps it.
         */
        #[ORM\Column(unique: true)]
        private string $slug,
        #[ORM\Embedded]
        private GardenLocation $location,
        #[ORM\Column]
        private \DateTimeImmutable $createdAt,
    ) {
        $this->id = Uuid::v7();
        $this->memberships = new ArrayCollection();
    }

    public static function create(string $name, string $slug, GardenLocation $location, User $owner, \DateTimeImmutable $createdAt): self
    {
        $garden = new self($name, $slug, $location, $createdAt);
        $garden->memberships->add(new Membership($garden, $owner, MembershipRole::Owner, $createdAt));

        return $garden;
    }

    /**
     * A new member, by an invitation (Invitation::accept()), or by the demo and the tests.
     */
    public function join(User $user, \DateTimeImmutable $joinedAt): void
    {
        if ($this->isMember($user)) {
            throw new \LogicException(\sprintf('User %s is a member of garden %s already.', $user->getId(), $this->getId()));
        }

        $this->memberships->add(new Membership($this, $user, MembershipRole::Member, $joinedAt));
    }

    /**
     * The oldest of the other members takes it over when its owner leaves: the one who knows it best. Its last member
     * does not leave it: the garden goes with them (App\Account\AccountDeletion). Their conversations and notifications
     * of it go too: App\Garden\MemberDeparture.
     */
    public function leave(User $user): void
    {
        $membership = $this->membershipOf($user) ?? throw new \LogicException(\sprintf('User %s is no member of garden %s.', $user->getId(), $this->getId()));
        if (1 === $this->memberships->count()) {
            throw new \LogicException(\sprintf('User %s is the last member of garden %s: it is deleted, not left.', $user->getId(), $this->getId()));
        }

        $this->memberships->removeElement($membership);
        $oldest = $this->memberships->first();
        if ($membership->isOwner() && false !== $oldest) {
            $oldest->takeOver();
        }
    }

    /**
     * By its owner, who never removes themselves: they leave it.
     */
    public function remove(Membership $membership): void
    {
        if ($membership->getGarden() !== $this || $membership->isOwner()) {
            throw new \LogicException(\sprintf('Only a member of garden %s who is not its owner is removed from it.', $this->getId()));
        }

        $this->leave($membership->getUser());
    }

    public function rename(string $name): void
    {
        $this->name = $name;
    }

    public function relocate(GardenLocation $location): void
    {
        $this->location = $location;
    }

    /**
     * From 7:00 in its timezone, once a day: a check missed at 7:00 (the worker was down) runs later that day.
     */
    public function isDueForReminderCheck(\DateTimeImmutable $now): bool
    {
        $localNow = $this->localTime($now);

        return (int) $localNow->format('G') >= self::REMINDER_CHECK_HOUR
            && $this->remindersCheckedOn?->format('Y-m-d') !== $localNow->format('Y-m-d');
    }

    public function markRemindersChecked(\DateTimeImmutable $now): void
    {
        $this->remindersCheckedOn = $this->localTime($now)->setTime(0, 0);
    }

    public function localTime(\DateTimeImmutable $now): \DateTimeImmutable
    {
        return $now->setTimezone($this->location->getTimezone());
    }

    public function isMember(User $user): bool
    {
        return null !== $this->membershipOf($user);
    }

    public function isOwnedBy(User $user): bool
    {
        return $this->membershipOf($user)?->isOwner() ?? false;
    }

    public function membershipOf(User $user): ?Membership
    {
        foreach ($this->memberships as $membership) {
            if ($membership->getUser() === $user) {
                return $membership;
            }
        }

        return null;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function getLocation(): GardenLocation
    {
        return $this->location;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getRemindersCheckedOn(): ?\DateTimeImmutable
    {
        return $this->remindersCheckedOn;
    }

    /**
     * @return list<Membership> the oldest first
     */
    public function getMemberships(): array
    {
        return array_values($this->memberships->toArray());
    }

    /**
     * @return list<User> the oldest first
     */
    public function getMembers(): array
    {
        return array_map(static fn (Membership $membership): User => $membership->getUser(), $this->getMemberships());
    }

    public function getOwner(): User
    {
        foreach ($this->memberships as $membership) {
            if ($membership->isOwner()) {
                return $membership->getUser();
            }
        }

        throw new \LogicException(\sprintf('Garden %s has no owner.', $this->getId()));
    }
}
