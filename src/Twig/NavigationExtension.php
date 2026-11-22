<?php

namespace App\Twig;

use App\Reminder\NotificationRepository;
use Twig\Attribute\AsTwigFunction;

final readonly class NavigationExtension
{
    public function __construct(
        private NotificationRepository $notificationRepository,
    ) {
    }

    #[AsTwigFunction('unread_notification_count')]
    public function unreadNotificationCount(): int
    {
        return $this->notificationRepository->countUnread();
    }
}
