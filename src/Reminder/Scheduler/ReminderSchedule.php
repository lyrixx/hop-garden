<?php

namespace App\Reminder\Scheduler;

use App\Reminder\Message\CheckRemindersMessage;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Consumed by the worker: "messenger:consume scheduler_default".
 */
#[AsSchedule]
final readonly class ReminderSchedule implements ScheduleProviderInterface
{
    public function __construct(
        private CacheInterface $cache,
        #[Autowire(env: 'GARDEN_TIMEZONE')]
        private string $gardenTimezone,
    ) {
    }

    public function getSchedule(): Schedule
    {
        $firstRun = new \DateTimeImmutable('07:00', new \DateTimeZone($this->gardenTimezone));

        return (new Schedule())
            ->stateful($this->cache) // a missed run (worker down at 7:00) is caught up on restart
            ->processOnlyLastMissedRun(true)
            ->add(RecurringMessage::every('1 day', new CheckRemindersMessage(), from: $firstRun))
        ;
    }
}
