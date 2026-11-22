<?php

namespace App\Reminder;

use App\Reminder\Entity\Reminder;
use App\Reminder\Model\UpcomingReminder;

final readonly class CalendarSchedule
{
    /**
     * When a calendar reminder is due next, within the coming $days: today if the current month is one of
     * its months, otherwise the first day of its next month.
     */
    public function nextOccurrence(Reminder $reminder, \DateTimeImmutable $today, int $days): ?\DateTimeImmutable
    {
        $months = $reminder->getType()->monthsIn($reminder->getPlant()->getCareSheet());
        if ([] === $months) {
            return null;
        }

        $today = $today->setTime(0, 0);
        $until = $today->modify("+{$days} days");

        if (\in_array((int) $today->format('n'), $months, true)) {
            return $today;
        }

        $month = $today->modify('first day of next month');
        while ($month <= $until) {
            if (\in_array((int) $month->format('n'), $months, true)) {
                return $month;
            }
            $month = $month->modify('first day of next month');
        }

        return null;
    }

    /**
     * @param list<Reminder> $reminders
     *
     * @return list<UpcomingReminder>
     */
    public function upcoming(array $reminders, \DateTimeImmutable $today, int $days): array
    {
        $upcoming = [];
        foreach ($reminders as $reminder) {
            if (!$reminder->getType()->isCalendar()) {
                continue;
            }

            $startsOn = $this->nextOccurrence($reminder, $today, $days);
            if (null !== $startsOn) {
                $upcoming[] = new UpcomingReminder($reminder, $startsOn);
            }
        }

        usort($upcoming, static fn (UpcomingReminder $a, UpcomingReminder $b): int => $a->startsOn <=> $b->startsOn);

        return $upcoming;
    }
}
