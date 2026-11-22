<?php

namespace App\Factory;

use App\Account\Entity\User;
use App\Garden\Entity\Garden;
use App\Garden\Entity\Invitation;
use App\Story\DefaultGardenStory;
use Zenstruck\Foundry\Factory;
use Zenstruck\Foundry\LazyValue;
use Zenstruck\Foundry\Object\Instantiator;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * A pending invitation into the default garden of the test (DefaultGardenStory) unless told, by its owner, sent on
 * 1 November 2026: it expires on the 15th. Its token is random unless given, the one its link carries.
 *
 * @extends PersistentObjectFactory<Invitation>
 */
final class InvitationFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return Invitation::class;
    }

    public function accepted(User $user, ?\DateTimeImmutable $acceptedAt = null): static
    {
        return $this->afterInstantiate(static function (Invitation $invitation) use ($user, $acceptedAt): void {
            $invitation->accept($user, $acceptedAt ?? $invitation->getCreatedAt()->modify('+1 day'));
        });
    }

    public function revoked(?\DateTimeImmutable $revokedAt = null): static
    {
        return $this->afterInstantiate(static function (Invitation $invitation) use ($revokedAt): void {
            $invitation->revoke($revokedAt ?? $invitation->getCreatedAt()->modify('+1 day'));
        });
    }

    protected function defaults(): array
    {
        return [
            'garden' => LazyValue::new(static fn () => DefaultGardenStory::get('garden')),
            'email' => self::faker()->unique()->safeEmail(),
            'token' => self::faker()->unique()->regexify('[A-Za-z0-9_-]{43}'),
            'sentAt' => new \DateTimeImmutable('2026-11-01 10:00'),
        ];
    }

    protected function initialize(): static
    {
        return $this
            ->instantiateWith(Instantiator::namedConstructor('send'))
            ->beforeInstantiate(static function (array $attributes): array {
                // Made first, for the owner of its garden, who invites unless told
                $garden = $attributes['garden'] ?? null;
                $garden = $garden instanceof LazyValue ? $garden() : $garden;
                $attributes['garden'] = $garden instanceof Factory ? $garden->create() : $garden;
                if (!isset($attributes['invitedBy']) && $attributes['garden'] instanceof Garden) {
                    $attributes['invitedBy'] = $attributes['garden']->getOwner();
                }

                return $attributes;
            })
        ;
    }
}
