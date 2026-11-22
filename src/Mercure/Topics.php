<?php

namespace App\Mercure;

use App\Account\Entity\User;
use App\Garden\Entity\Garden;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The Mercure topics, all private (the hub has no anonymous subscriber): URLs under the one of the application, so that
 * a URL pattern grants them by owner (subscriberGrants()). A member subscribes to their own topics, and to the ones of
 * their current garden:
 * - a conversation of the chat: /users/{id}/conversations/{uuid};
 * - the notifications of a garden: /users/{id}/gardens/{id}/notifications, each member their own;
 * - the drafting of a care sheet: /gardens/{id}/drafting/{uuid}, on the page of the planting, for every member.
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
        return \sprintf('%s/users/%d/conversations/%s', $this->base, $user->getId(), $conversationId);
    }

    public function notifications(User $user, Garden $garden): string
    {
        return \sprintf('%s/users/%d/gardens/%d/notifications', $this->base, $user->getId(), $garden->getId());
    }

    public function drafting(Garden $garden, string $conversationId): string
    {
        return \sprintf('%s/gardens/%d/drafting/%s', $this->base, $garden->getId(), $conversationId);
    }

    /**
     * What the subscriber cookie of a member grants: their topics, and the ones of their current garden. Nothing of
     * another member, nor of another garden.
     *
     * @return array{urlpattern: list<string>}
     */
    public function subscriberGrants(User $user, ?Garden $garden): array
    {
        $patterns = [\sprintf('%s/users/%d/*', $this->base, $user->getId())];
        if (null !== $garden) {
            $patterns[] = \sprintf('%s/gardens/%d/*', $this->base, $garden->getId());
        }

        return ['urlpattern' => $patterns];
    }
}
