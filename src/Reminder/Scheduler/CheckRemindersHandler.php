<?php

namespace App\Reminder\Scheduler;

use App\Reminder\Message\CheckRemindersMessage;
use App\Reminder\ReminderChecker;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class CheckRemindersHandler
{
    public function __construct(
        private ReminderChecker $reminderChecker,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(CheckRemindersMessage $message): void
    {
        $this->reminderChecker->check($this->clock->now());
        $this->entityManager->flush();
    }
}
