<?php

namespace App\Garden\Scheduler;

use App\Garden\Entity\Invitation;
use App\Garden\InvitationRepository;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Scheduler\Attribute\AsPeriodicTask;

/**
 * Every day at 3:00 UTC, in the schedule of the reminders (App\Reminder\Scheduler\ReminderSchedule, consumed by the
 * worker: "messenger:consume scheduler_default"), which the attribute adds it to: the invitations ended 30 days ago are
 * deleted, and the address they were sent to with them, even when its account is long gone.
 */
#[AsPeriodicTask(frequency: '1 day', from: 'today 03:00 UTC')]
final readonly class PurgeEndedInvitations
{
    public function __construct(
        private InvitationRepository $invitationRepository,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(): void
    {
        $deleted = $this->invitationRepository->deleteEndedBefore($this->clock->now()->modify('-' . Invitation::KEPT_AFTER_ITS_END));

        $this->logger->info('Ended invitations purged.', ['count' => $deleted]);
    }
}
