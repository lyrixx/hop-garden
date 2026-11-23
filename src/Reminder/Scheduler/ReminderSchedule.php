<?php

namespace App\Reminder\Scheduler;

use App\Reminder\Message\CheckRemindersMessage;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Consumed by the worker: "messenger:consume scheduler_default". Every hour, on the hour: each garden is checked once a
 * day, from 7:00 in its own timezone (CheckRemindersHandler, App\Garden\Entity\Garden::isDueForReminderCheck()). The
 * tasks of the other domains join it with #[AsPeriodicTask]: App\Garden\Scheduler\PurgeEndedInvitations.
 */
#[AsSchedule]
final readonly class ReminderSchedule implements ScheduleProviderInterface
{
    public function __construct(
        private CacheInterface $cache,
    ) {
    }

    public function getSchedule(): Schedule
    {
        $firstRun = new \DateTimeImmutable('today 00:00', new \DateTimeZone('UTC'));

        return (new Schedule())
            ->stateful($this->cache) // a missed run (worker down) is caught up on restart
            ->processOnlyLastMissedRun(true)
            ->add(RecurringMessage::every('1 hour', new CheckRemindersMessage(), from: $firstRun))
        ;
    }
}
