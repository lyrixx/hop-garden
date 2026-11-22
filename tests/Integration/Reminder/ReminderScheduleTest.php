<?php

namespace App\Tests\Integration\Reminder;

use App\Reminder\Message\CheckRemindersMessage;
use App\Reminder\Scheduler\ReminderSchedule;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Scheduler\Generator\MessageContext;
use Symfony\Component\Scheduler\RecurringMessage;

/**
 * Every hour, on the hour: each garden is checked from 7:00 in its own timezone (ReminderCheckerTest), whatever the
 * timezone of the server. Read from the real clock: the runs are checked from it, never against a fixed date.
 */
final class ReminderScheduleTest extends KernelTestCase
{
    public function testTheRemindersAreCheckedEveryHourOnTheHour(): void
    {
        self::bootKernel();
        $schedule = self::getContainer()->get(ReminderSchedule::class)->getSchedule();

        $recurringMessages = $schedule->getRecurringMessages();
        self::assertCount(1, $recurringMessages);
        $trigger = $recurringMessages[0]->getTrigger();
        $messages = self::messagesOf($recurringMessages[0]);
        self::assertCount(1, $messages);
        self::assertInstanceOf(CheckRemindersMessage::class, $messages[0]);
        self::assertSame('every 1 hour', (string) $trigger);

        $run = $trigger->getNextRunDate(new \DateTimeImmutable('2000-01-01')) ?? self::fail('The schedule never runs.');
        // Over the changes of daylight saving time of the gardens too: hours of UTC
        for ($hour = 1; $hour <= 24 * 400; ++$hour) {
            $next = $trigger->getNextRunDate($run) ?? self::fail('The schedule stopped.');
            self::assertSame($run->modify('+1 hour')->format('Y-m-d H:i'), $next->format('Y-m-d H:i'));
            self::assertSame('00', $next->format('i'), 'On the hour.');
            $run = $next;
        }
    }

    public function testAMissedRunIsCaughtUpOnce(): void
    {
        self::bootKernel();
        $schedule = self::getContainer()->get(ReminderSchedule::class)->getSchedule();

        self::assertNotNull($schedule->getState(), 'The last run is remembered.');
        self::assertTrue($schedule->shouldProcessOnlyLastMissedRun(), 'A worker down for days checks once on restart.');
    }

    /**
     * @return list<object>
     */
    private static function messagesOf(RecurringMessage $recurringMessage): array
    {
        $context = new MessageContext('default', $recurringMessage->getId(), $recurringMessage->getTrigger(), new \DateTimeImmutable());

        return array_values([...$recurringMessage->getProvider()->getMessages($context)]);
    }
}
