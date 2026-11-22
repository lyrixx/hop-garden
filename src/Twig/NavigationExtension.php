<?php

namespace App\Twig;

use App\Account\Entity\User;
use App\Garden\CurrentGarden;
use App\Garden\Entity\Garden;
use App\Garden\GardenRepository;
use App\Mercure\Topics;
use App\Reminder\NotificationRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Mercure\Authorization;
use Twig\Attribute\AsTwigFunction;

/**
 * The top bar of the member: their gardens, the unread notifications of the current one, and their Mercure topics.
 */
final readonly class NavigationExtension
{
    public function __construct(
        private Security $security,
        private CurrentGarden $currentGarden,
        private GardenRepository $gardenRepository,
        private NotificationRepository $notificationRepository,
        private Topics $topics,
        private Authorization $mercureAuthorization,
        private RequestStack $requestStack,
    ) {
    }

    #[AsTwigFunction('current_garden')]
    public function currentGarden(): ?Garden
    {
        return $this->currentGarden->find();
    }

    /**
     * @return list<Garden> the one joined first first
     */
    #[AsTwigFunction('member_gardens')]
    public function memberGardens(): array
    {
        $user = $this->user();

        return null !== $user ? $this->gardenRepository->findOfMember($user) : [];
    }

    #[AsTwigFunction('unread_notification_count')]
    public function unreadNotificationCount(): int
    {
        $user = $this->user();
        $garden = $this->currentGarden->find();

        return null !== $user && null !== $garden ? $this->notificationRepository->countUnread($user, $garden) : 0;
    }

    #[AsTwigFunction('notifications_topic')]
    public function notificationsTopic(): ?string
    {
        $user = $this->user();
        $garden = $this->currentGarden->find();

        return null !== $user && null !== $garden ? $this->topics->notifications($user, $garden) : null;
    }

    /**
     * The subscriber cookie of the Mercure hub, set by every page of a member: their topics, and the ones of their
     * current garden (Topics::subscriberGrants()). The streams of a page merge their own topics into it.
     */
    #[AsTwigFunction('grant_mercure_topics')]
    public function grantMercureTopics(): string
    {
        $user = $this->user();
        $request = $this->requestStack->getMainRequest();
        if (null !== $user && null !== $request) {
            $this->mercureAuthorization->setCookie($request, $this->topics->subscriberGrants($user, $this->currentGarden->find()));
        }

        return '';
    }

    private function user(): ?User
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $user : null;
    }
}
