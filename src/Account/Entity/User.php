<?php

namespace App\Account\Entity;

use App\Account\Model\AccessStatus;
use App\Account\UserRepository;
use App\Locale\Model\SupportedLocale;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\NilUuid;
use Symfony\Component\Uid\Uuid;

/**
 * Someone with an account: they register, verify their email, then wait until the administrator approves them, unless
 * an invitation to a garden lets them in. Only an approved member gets in.
 */
#[ORM\Entity(repositoryClass: UserRepository::class)]
// "user" is a reserved word of PostgreSQL
#[ORM\Table(name: 'app_user')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    public const string ROLE_ADMIN = 'ROLE_ADMIN';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    /**
     * Lower case: an address is found whatever the case it is typed in.
     */
    #[ORM\Column(unique: true)]
    private string $email;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $emailVerifiedAt = null;

    #[ORM\Column]
    private AccessStatus $accessStatus = AccessStatus::Pending;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $approvedAt = null;

    /**
     * Granted on top of ROLE_USER, which comes with the approval.
     *
     * @var list<string>
     */
    #[ORM\Column]
    private array $roles = [];

    private function __construct(
        string $email,
        #[ORM\Column]
        private string $displayName,
        #[ORM\Column]
        private string $password,
        /**
         * Null until they choose one: the interface then offers the language of the browser.
         */
        #[ORM\Column(nullable: true)]
        private ?SupportedLocale $preferredLocale,
        #[ORM\Column]
        private \DateTimeImmutable $createdAt,
    ) {
        $this->id = Uuid::v7();
        $this->email = self::normalizeEmail($email);
    }

    /**
     * The session keeps a checksum of the password hash, not the hash: Symfony compares it on each request.
     *
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        $data = (array) $this;
        $data["\0" . self::class . "\0password"] = hash('crc32c', $this->password);

        return $data;
    }

    /**
     * A session of before the UUIDs holds an int id: the refresh of its member finds nobody, and logs them out, as an
     * account deleted since, instead of failing on the type of the id.
     *
     * @param array<string, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        $id = "\0" . self::class . "\0id";
        if (!($data[$id] ?? null) instanceof Uuid) {
            $data[$id] = new NilUuid();
        }

        foreach ($data as $property => $value) {
            // "\0App\Account\Entity\User\0email": its properties are private
            $this->{substr($property, (int) strrpos($property, "\0") + 1)} = $value;
        }
    }

    /**
     * On the waiting list, once they verify their email.
     */
    public static function register(string $email, string $displayName, string $passwordHash, ?SupportedLocale $preferredLocale, \DateTimeImmutable $registeredAt): self
    {
        return new self($email, $displayName, $passwordHash, $preferredLocale, $registeredAt);
    }

    public static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    public function verifyEmail(\DateTimeImmutable $at): void
    {
        $this->emailVerifiedAt ??= $at;
    }

    /**
     * Its email verified, and no decision yet.
     */
    public function isWaiting(): bool
    {
        return AccessStatus::Pending === $this->accessStatus && null !== $this->emailVerifiedAt;
    }

    public function approve(\DateTimeImmutable $at): void
    {
        $this->assertWaiting();

        $this->accessStatus = AccessStatus::Approved;
        $this->approvedAt = $at;
    }

    /**
     * Invited into a garden, an account without a decision skips the waiting list (Invitation::accept()), a new one or
     * one still waiting: in once its email is verified.
     */
    public function admitByInvitation(\DateTimeImmutable $at): void
    {
        if (AccessStatus::Pending !== $this->accessStatus) {
            throw new \LogicException(\sprintf('Only an account without a decision is admitted by an invitation, not one %s.', $this->accessStatus->value));
        }

        $this->accessStatus = AccessStatus::Approved;
        $this->approvedAt = $at;
    }

    public function refuse(): void
    {
        $this->assertWaiting();

        $this->accessStatus = AccessStatus::Refused;
    }

    public function grantAdmin(): void
    {
        $this->roles = array_values(array_unique([...$this->roles, self::ROLE_ADMIN]));
    }

    public function rename(string $displayName): void
    {
        $this->displayName = $displayName;
    }

    public function choosePreferredLocale(SupportedLocale $locale): void
    {
        $this->preferredLocale = $locale;
    }

    public function changePassword(string $passwordHash): void
    {
        $this->password = $passwordHash;
    }

    /**
     * From the link of a password reset email, which proves the address too.
     */
    public function resetPassword(string $passwordHash, \DateTimeImmutable $at): void
    {
        $this->password = $passwordHash;
        $this->verifyEmail($at);
    }

    /**
     * From the link sent to the new address, which proves it.
     */
    public function changeEmail(string $email, \DateTimeImmutable $verifiedAt): void
    {
        $this->email = self::normalizeEmail($email);
        $this->emailVerifiedAt = $verifiedAt;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getDisplayName(): string
    {
        return $this->displayName;
    }

    public function getPreferredLocale(): ?SupportedLocale
    {
        return $this->preferredLocale;
    }

    public function getEmailVerifiedAt(): ?\DateTimeImmutable
    {
        return $this->emailVerifiedAt;
    }

    public function isEmailVerified(): bool
    {
        return null !== $this->emailVerifiedAt;
    }

    public function getAccessStatus(): AccessStatus
    {
        return $this->accessStatus;
    }

    public function getApprovedAt(): ?\DateTimeImmutable
    {
        return $this->approvedAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function isAdmin(): bool
    {
        return \in_array(self::ROLE_ADMIN, $this->roles, true);
    }

    public function getUserIdentifier(): string
    {
        // Never empty: the forms and app:user:create require an email
        return $this->email; // @phpstan-ignore return.type
    }

    /**
     * ROLE_USER opens the pages of the garden: it comes with the approval only. Every request compares the roles of the
     * session with these: a member whose access changes is logged out.
     */
    public function getRoles(): array
    {
        if (AccessStatus::Approved !== $this->accessStatus) {
            return [];
        }

        return array_values(array_unique(['ROLE_USER', ...$this->roles]));
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    private function assertWaiting(): void
    {
        if (!$this->isWaiting()) {
            throw new \LogicException(\sprintf('Only an account on the waiting list gets a decision, not one %s%s.', $this->accessStatus->value, $this->isEmailVerified() ? '' : ' with an unverified email'));
        }
    }
}
