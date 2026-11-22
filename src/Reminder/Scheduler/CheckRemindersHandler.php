<?php

namespace App\Reminder\Scheduler;

use App\Locale\PreferredLocale;
use App\Reminder\Message\CheckRemindersMessage;
use App\Reminder\ReminderChecker;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Translation\LocaleSwitcher;

#[AsMessageHandler]
final readonly class CheckRemindersHandler
{
    public function __construct(
        private ReminderChecker $reminderChecker,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
        private LocaleSwitcher $localeSwitcher,
        private PreferredLocale $preferredLocale,
    ) {
    }

    public function __invoke(CheckRemindersMessage $message): void
    {
        // The scheduler has no recipient: the preferred locale of the application, for now. With accounts, each
        // notification in the locale of its recipient. The toasts are rendered on flush, so it flushes in it too
        $this->localeSwitcher->runWithLocale($this->preferredLocale->get()->value, function (): void {
            $this->reminderChecker->check($this->clock->now());
            $this->entityManager->flush();
        });
    }
}
