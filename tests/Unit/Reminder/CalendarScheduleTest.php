<?php

namespace App\Tests\Unit\Reminder;

use App\Factory\CareSheetFactory;
use App\Factory\PlantFactory;
use App\Factory\ReminderFactory;
use App\Reminder\CalendarSchedule;
use App\Reminder\Model\ReminderType;
use App\Reminder\Model\UpcomingReminder;
use PHPUnit\Framework\TestCase;

final class CalendarScheduleTest extends TestCase
{
    public function testNextOccurrence(): void
    {
        $plant = PlantFactory::new()->validated()->create(['careSheet' => CareSheetFactory::new(['pruningMonths' => [3, 11]])]);
        $reminder = $plant->enableReminder(ReminderType::Pruning);
        $schedule = new CalendarSchedule();

        self::assertSame('2026-03-10', $schedule->nextOccurrence($reminder, new \DateTimeImmutable('2026-03-10 07:00'), 30)?->format('Y-m-d'), 'Due now.');
        self::assertSame('2026-03-01', $schedule->nextOccurrence($reminder, new \DateTimeImmutable('2026-02-10 07:00'), 30)?->format('Y-m-d'));
        self::assertNull($schedule->nextOccurrence($reminder, new \DateTimeImmutable('2026-01-10 07:00'), 30), 'Too far.');
        self::assertSame('2027-03-01', $schedule->nextOccurrence($reminder, new \DateTimeImmutable('2026-12-15 07:00'), 90)?->format('Y-m-d'), 'Next year.');
    }

    public function testAFrostReminderHasNoCalendar(): void
    {
        $plant = PlantFactory::new()->validated()->create();
        $schedule = new CalendarSchedule();

        self::assertSame([], $schedule->upcoming([$plant->enableReminder(ReminderType::Frost)], new \DateTimeImmutable('2026-03-01'), 365));
    }

    public function testAFrostEpisodeIsExtendedByContiguousFrostDays(): void
    {
        $reminder = ReminderFactory::createOne(['type' => ReminderType::Frost]);
        $now = new \DateTimeImmutable('2026-12-01 07:00');

        self::assertTrue($reminder->recordFrostEpisode(new \DateTimeImmutable('2026-12-01'), new \DateTimeImmutable('2026-12-03'), $now));
        self::assertFalse($reminder->recordFrostEpisode(new \DateTimeImmutable('2026-12-04'), new \DateTimeImmutable('2026-12-06'), $now), 'Starts the day after: same episode.');
        self::assertSame('2026-12-06', $reminder->getFrostEpisodeUntil()?->format('Y-m-d'));
        self::assertTrue($reminder->recordFrostEpisode(new \DateTimeImmutable('2026-12-08'), new \DateTimeImmutable('2026-12-08'), $now), 'A day without frost in between: new episode.');
    }

    public function testAReminderWithoutMonthsIsNeverDue(): void
    {
        $plant = PlantFactory::new()->validated()->create(['careSheet' => CareSheetFactory::new(['deadheadingMonths' => []])]);

        self::assertNull((new CalendarSchedule())->nextOccurrence($plant->enableReminder(ReminderType::Deadheading), new \DateTimeImmutable('2026-07-01'), 365));
    }

    public function testTheLastDayOfTheWindowCounts(): void
    {
        $plant = PlantFactory::new()->validated()->create(['careSheet' => CareSheetFactory::new(['pruningMonths' => [3]])]);
        $reminder = $plant->enableReminder(ReminderType::Pruning);
        $schedule = new CalendarSchedule();

        // 1 March is 19 days after 10 February 2026
        self::assertSame('2026-03-01 00:00', $schedule->nextOccurrence($reminder, new \DateTimeImmutable('2026-02-10 18:30'), 19)?->format('Y-m-d H:i'));
        self::assertNull($schedule->nextOccurrence($reminder, new \DateTimeImmutable('2026-02-10'), 18));
    }

    public function testUpcomingRemindersSoonestFirst(): void
    {
        $rose = PlantFactory::new()->validated()->create(['commonName' => 'Rose', 'careSheet' => CareSheetFactory::new(['pruningMonths' => [1], 'deadheadingMonths' => [12], 'cuttingMonths' => [6]])]);
        $reminders = [
            $rose->enableReminder(ReminderType::Pruning),
            $rose->enableReminder(ReminderType::Deadheading),
            $rose->enableReminder(ReminderType::Cutting),
            $rose->enableReminder(ReminderType::Frost),
        ];

        $upcoming = (new CalendarSchedule())->upcoming($reminders, new \DateTimeImmutable('2026-11-21 07:00'), 60);

        self::assertSame(
            [['deadheading', '2026-12-01'], ['pruning', '2027-01-01']],
            array_map(static fn (UpcomingReminder $reminder): array => [$reminder->reminder->getType()->value, $reminder->startsOn->format('Y-m-d')], $upcoming),
            'Across the new year; the cuttings of June are too far, the frost alert follows the forecast.',
        );
    }
}
