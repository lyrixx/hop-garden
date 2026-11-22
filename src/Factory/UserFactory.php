<?php

namespace App\Factory;

use App\Account\Entity\User;
use Zenstruck\Foundry\Object\Instantiator;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * An account just registered, its email not verified yet: verified() puts it on the waiting list, approved() lets it
 * in, admin() opens the back-office too.
 *
 * @extends PersistentObjectFactory<User>
 */
final class UserFactory extends PersistentObjectFactory
{
    /**
     * Of every account it creates, the demo ones too: "castor demo:reset" prints it.
     */
    public const string PASSWORD = 'hop-garden-demo';

    // Before the states that need a verified or an approved account, whatever their order
    private const int VERIFICATION_PRIORITY = 20;
    private const int DECISION_PRIORITY = 10;

    public static function class(): string
    {
        return User::class;
    }

    /**
     * Ten minutes after registering, by default.
     */
    public function verified(?\DateTimeImmutable $verifiedAt = null): static
    {
        return $this->afterInstantiate(
            static function (User $user) use ($verifiedAt): void {
                $user->verifyEmail($verifiedAt ?? $user->getCreatedAt()->modify('+10 minutes'));
            },
            self::VERIFICATION_PRIORITY,
        );
    }

    /**
     * The day after registering, by default.
     */
    public function approved(?\DateTimeImmutable $approvedAt = null): static
    {
        return $this->verified()->afterInstantiate(
            static function (User $user) use ($approvedAt): void {
                $user->approve($approvedAt ?? $user->getCreatedAt()->modify('+1 day'));
            },
            self::DECISION_PRIORITY,
        );
    }

    public function refused(): static
    {
        return $this->verified()->afterInstantiate(static function (User $user): void {
            $user->refuse();
        }, self::DECISION_PRIORITY);
    }

    public function admin(): static
    {
        return $this->approved()->afterInstantiate(static function (User $user): void {
            $user->grantAdmin();
        });
    }

    protected function defaults(): array
    {
        return [
            'email' => self::faker()->unique()->safeEmail(),
            'displayName' => 'Alice',
            'passwordHash' => self::passwordHash(),
            'preferredLocale' => null,
            'registeredAt' => new \DateTimeImmutable('2026-11-01 10:00'),
        ];
    }

    protected function initialize(): static
    {
        return $this->instantiateWith(Instantiator::namedConstructor('register'));
    }

    /**
     * As the "auto" hasher of Symfony verifies it: bcrypt, at its lowest cost.
     */
    private static function passwordHash(): string
    {
        return password_hash(self::PASSWORD, PASSWORD_BCRYPT, ['cost' => 4]);
    }
}
