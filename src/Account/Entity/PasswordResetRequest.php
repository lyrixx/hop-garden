<?php

namespace App\Account\Entity;

use App\Account\PasswordResetRequestRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * "I forgot my password": the link of the email carries the token, the database only its hash.
 */
#[ORM\Entity(repositoryClass: PasswordResetRequestRepository::class)]
class PasswordResetRequest
{
    private const string LIFETIME = '+1 hour';

    // Only Doctrine reads it
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null; // @phpstan-ignore property.onlyWritten

    // Only the queries read it: PasswordResetRequestRepository::findOneByToken()
    #[ORM\Column(unique: true)]
    private string $tokenHash; // @phpstan-ignore property.onlyWritten

    #[ORM\Column]
    private \DateTimeImmutable $expiresAt;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private User $user,
        #[\SensitiveParameter]
        string $token,
        #[ORM\Column]
        private \DateTimeImmutable $requestedAt,
    ) {
        $this->tokenHash = self::hash($token);
        $this->expiresAt = $requestedAt->modify(self::LIFETIME);
    }

    /**
     * SHA-256 is enough: the token is random, there is nothing to guess from its hash.
     */
    public static function hash(#[\SensitiveParameter] string $token): string
    {
        return hash('sha256', $token);
    }

    public function isExpired(\DateTimeImmutable $now): bool
    {
        return $now >= $this->expiresAt;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }
}
