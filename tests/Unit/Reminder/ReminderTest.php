<?php

namespace App\Tests\Unit\Reminder;

use App\Reminder\Entity\Reminder;
use App\Reminder\Model\ReminderType;
use App\Tests\Utils\Factory\PlantFactory;
use PHPUnit\Framework\TestCase;

final class ReminderTest extends TestCase
{
    public function testANewReminderIsOff(): void
    {
        $reminder = new Reminder(PlantFactory::validated(), ReminderType::Pruning);

        self::assertFalse($reminder->isEnabled());
        self::assertNull($reminder->getLastTriggeredAt());

        $reminder->enable();
        self::assertTrue($reminder->isEnabled());

        $reminder->disable();
        self::assertFalse($reminder->isEnabled());
    }

    public function testACalendarReminderRemembersTheMonthItWasTriggeredIn(): void
    {
        $reminder = new Reminder(PlantFactory::validated(), ReminderType::Pruning);
        self::assertFalse($reminder->wasTriggeredInMonthOf(new \DateTimeImmutable('2026-03-01')));

        $reminder->markTriggered(new \DateTimeImmutable('2026-03-01 07:00'));

        self::assertTrue($reminder->wasTriggeredInMonthOf(new \DateTimeImmutable('2026-03-31 23:59')));
        self::assertFalse($reminder->wasTriggeredInMonthOf(new \DateTimeImmutable('2026-04-01')));
        self::assertFalse($reminder->wasTriggeredInMonthOf(new \DateTimeImmutable('2027-03-01')), 'Not the same month of another year.');
    }

    public function testTheFirstFrostEpisodeIsANewOne(): void
    {
        $reminder = new Reminder(PlantFactory::validated(), ReminderType::Frost);

        self::assertTrue($reminder->recordFrostEpisode(new \DateTimeImmutable('2026-12-01 06:00'), new \DateTimeImmutable('2026-12-03 06:00'), new \DateTimeImmutable('2026-12-01 07:00')));

        self::assertSame('2026-12-03 00:00', $reminder->getFrostEpisodeUntil()?->format('Y-m-d H:i'), 'Days, not times.');
        self::assertSame('2026-12-01 07:00', $reminder->getLastTriggeredAt()?->format('Y-m-d H:i'));
    }

    public function testFrostDaysWithinTheAnnouncedEpisodeDoNotShortenIt(): void
    {
        $reminder = new Reminder(PlantFactory::validated(), ReminderType::Frost);
        $reminder->recordFrostEpisode(new \DateTimeImmutable('2026-12-01'), new \DateTimeImmutable('2026-12-05'), new \DateTimeImmutable('2026-12-01 07:00'));

        self::assertFalse($reminder->recordFrostEpisode(new \DateTimeImmutable('2026-12-02'), new \DateTimeImmutable('2026-12-03'), new \DateTimeImmutable('2026-12-02 07:00')));

        self::assertSame('2026-12-05', $reminder->getFrostEpisodeUntil()?->format('Y-m-d'));
        self::assertSame('2026-12-01 07:00', $reminder->getLastTriggeredAt()?->format('Y-m-d H:i'), 'No new alert.');
    }

    public function testAFrostDayRightAfterTheEpisodeExtendsIt(): void
    {
        $reminder = new Reminder(PlantFactory::validated(), ReminderType::Frost);
        $reminder->recordFrostEpisode(new \DateTimeImmutable('2026-12-01'), new \DateTimeImmutable('2026-12-03'), new \DateTimeImmutable('2026-12-01 07:00'));

        self::assertFalse($reminder->recordFrostEpisode(new \DateTimeImmutable('2026-12-04'), new \DateTimeImmutable('2026-12-06'), new \DateTimeImmutable('2026-12-04 07:00')));
        self::assertSame('2026-12-06', $reminder->getFrostEpisodeUntil()?->format('Y-m-d'));
    }

    public function testADayWithoutFrostStartsANewEpisode(): void
    {
        $reminder = new Reminder(PlantFactory::validated(), ReminderType::Frost);
        $reminder->recordFrostEpisode(new \DateTimeImmutable('2026-12-01'), new \DateTimeImmutable('2026-12-03'), new \DateTimeImmutable('2026-12-01 07:00'));

        self::assertTrue($reminder->recordFrostEpisode(new \DateTimeImmutable('2026-12-05'), new \DateTimeImmutable('2026-12-05'), new \DateTimeImmutable('2026-12-05 07:00')));

        self::assertSame('2026-12-05', $reminder->getFrostEpisodeUntil()?->format('Y-m-d'));
        self::assertSame('2026-12-05 07:00', $reminder->getLastTriggeredAt()?->format('Y-m-d H:i'));
    }

    public function testTheTypeTellsWhichMonthsOfTheCareSheetApply(): void
    {
        $careSheet = PlantFactory::careSheet(pruningMonths: [2, 3], deadheadingMonths: [7], cuttingMonths: [9]);

        self::assertSame([2, 3], ReminderType::Pruning->monthsIn($careSheet));
        self::assertSame([7], ReminderType::Deadheading->monthsIn($careSheet));
        self::assertSame([9], ReminderType::Cutting->monthsIn($careSheet));
        self::assertSame([], ReminderType::Frost->monthsIn($careSheet), 'The forecast decides.');
        self::assertSame([ReminderType::Pruning, ReminderType::Deadheading, ReminderType::Cutting], array_values(array_filter(ReminderType::cases(), static fn (ReminderType $type): bool => $type->isCalendar())));
        self::assertSame(ReminderType::calendarTypes(), array_values(array_filter(ReminderType::cases(), static fn (ReminderType $type): bool => $type->isCalendar())));
    }
}
