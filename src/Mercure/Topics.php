<?php

namespace App\Mercure;

use App\Account\Entity\User;
use App\Garden\Entity\Garden;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The Mercure topics, all private (the hub has no anonymous subscriber): URLs under the one of the application, so that
 * a URL pattern grants them by owner (subscriberGrants()). A member subscribes to their own topics, and to the ones of
 * their current garden, every id a UUID:
 * - a conversation of the chat: /users/{id}/conversations/{id};
 * - the notifications of a garden: /users/{id}/gardens/{id}/notifications, each member their own;
 * - the drafting of a care sheet: /gardens/{id}/drafting/{id}, on the page of the planting, for every member.
 */
final readonly class Topics
{
    private string $base;

    public function __construct(
        #[Autowire(env: 'DEFAULT_URI')]
        string $defaultUri,
    ) {
        $this->base = rtrim($defaultUri, '/');
    }

    public function conversation(User $user, string $conversationId): string
    {
        return \sprintf('%s/users/%s/conversations/%s', $this->base, $user->getId()->toRfc4122(), $conversationId);
    }

    public function notifications(User $user, Garden $garden): string
    {
        return \sprintf('%s/users/%s/gardens/%s/notifications', $this->base, $user->getId()->toRfc4122(), $garden->getId()->toRfc4122());
    }

    public function drafting(Garden $garden, string $conversationId): string
    {
        return \sprintf('%s/gardens/%s/drafting/%s', $this->base, $garden->getId()->toRfc4122(), $conversationId);
    }

    /**
     * What the subscriber cookie of a member grants: their topics, and the ones of their current garden. Nothing of
     * another member, nor of another garden.
     *
     * @return array{urlpattern: list<string>}
     */
    public function subscriberGrants(User $user, ?Garden $garden): array
    {
        $patterns = [\sprintf('%s/users/%s/*', $this->base, $user->getId()->toRfc4122())];
        if (null !== $garden) {
            $patterns[] = \sprintf('%s/gardens/%s/*', $this->base, $garden->getId()->toRfc4122());
        }

        return ['urlpattern' => $patterns];
    }
}
