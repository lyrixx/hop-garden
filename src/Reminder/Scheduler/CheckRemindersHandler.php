<?php

namespace App\Reminder\Scheduler;

use App\Garden\GardenRepository;
use App\Reminder\Message\CheckRemindersMessage;
use App\Reminder\ReminderChecker;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Every hour: the gardens where it is 7:00 or later and not checked yet today, each at its position and in its
 * timezone. Each member gets their notifications in their language (ReminderChecker), and their toasts too, rendered
 * on flush (NotificationToastPublisher). A garden flushed at once: a failure leaves the others checked.
 */
#[AsMessageHandler]
final readonly class CheckRemindersHandler
{
    public function __construct(
        private GardenRepository $gardenRepository,
        private ReminderChecker $reminderChecker,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(CheckRemindersMessage $message): void
    {
        $now = $this->clock->now();

        foreach ($this->gardenRepository->findAllWithMembers() as $garden) {
            if (!$garden->isDueForReminderCheck($now)) {
                continue;
            }

            $this->reminderChecker->check($garden, $now);
            $garden->markRemindersChecked($now);
            $this->entityManager->flush();
        }
    }
}
