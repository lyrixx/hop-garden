<?php

namespace App\Twig;

use App\Account\Entity\User;
use App\Garden\CurrentGarden;
use App\Garden\Entity\Garden;
use App\Garden\GardenRepository;
use App\Garden\LastGarden;
use App\Garden\Security\GardenVoter;
use App\Mercure\SubscriberCookie;
use App\Mercure\Topics;
use App\Reminder\NotificationRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Attribute\AsTwigFunction;

/**
 * The top bar of the member: their gardens, the unread notifications of the garden of the page, and their Mercure
 * topics. A page out of a garden (the account, the wizard) shows the last one (LastGarden).
 */
final readonly class NavigationExtension
{
    public function __construct(
        private Security $security,
        private CurrentGarden $currentGarden,
        private LastGarden $lastGarden,
        private GardenRepository $gardenRepository,
        private NotificationRepository $notificationRepository,
        private RequestStack $requestStack,
        private SubscriberCookie $subscriberCookie,
        private Topics $topics,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * The garden of the URL, else the last one of the member: the one their links lead to. Null for a visitor or a member
     * without garden yet.
     */
    #[AsTwigFunction('nav_garden')]
    public function navGarden(): ?Garden
    {
        return $this->currentGarden->find() ?? $this->lastGarden->find();
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

    /**
     * The same page in another garden of the member: a page of the garden as a whole (its assistant, its plants, its
     * species...) is in every garden, and the settings of one they manage. Else, a page of one of its resources, its
     * assistant.
     */
    #[AsTwigFunction('garden_switch_path')]
    public function gardenSwitchPath(Garden $garden): string
    {
        $request = $this->requestStack->getMainRequest();
        $route = $request?->attributes->get('_route');
        $parameters = $request?->attributes->get('_route_params');

        $samePage = \is_string($route) && \is_array($parameters) && ['garden'] === array_keys($parameters)
            && ('garden_settings' !== $route || $this->security->isGranted(GardenVoter::MANAGE, $garden));

        return $this->urlGenerator->generate($samePage ? $route : 'chat', ['garden' => $garden->getSlug()]);
    }

    #[AsTwigFunction('unread_notification_count')]
    public function unreadNotificationCount(): int
    {
        $user = $this->user();
        $garden = $this->navGarden();

        return null !== $user && null !== $garden ? $this->notificationRepository->countUnread($user, $garden) : 0;
    }

    #[AsTwigFunction('notifications_topic')]
    public function notificationsTopic(): ?string
    {
        $user = $this->user();
        $garden = $this->navGarden();

        return null !== $user && null !== $garden ? $this->topics->notifications($user, $garden) : null;
    }

    /**
     * The subscriber cookie of the Mercure hub, set by every page of a member (SubscriberCookie). The streams of a page
     * merge their own topics into it.
     */
    #[AsTwigFunction('grant_mercure_topics')]
    public function grantMercureTopics(): string
    {
        $user = $this->user();
        $request = $this->requestStack->getMainRequest();
        if (null !== $user && null !== $request) {
            $this->subscriberCookie->set($request, $user);
        }

        return '';
    }

    private function user(): ?User
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $user : null;
    }
}
