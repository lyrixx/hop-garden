<?php

namespace App\Tests\Integration\Reminder;

use App\Reminder\Message\CheckRemindersMessage;
use App\Reminder\Scheduler\ReminderSchedule;
use App\Tests\Utils\Env\EnvVarsTrait;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Scheduler\Generator\MessageContext;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Trigger\TriggerInterface;

/**
 * The first run of the schedule is today at 7:00, read from the real clock: the runs are checked from it, never
 * against a fixed date.
 */
final class ReminderScheduleTest extends KernelTestCase
{
    use EnvVarsTrait;

    public function testTheRemindersAreCheckedEveryDayAt7InTheTimezoneOfTheGarden(): void
    {
        self::bootKernel();
        $schedule = self::getContainer()->get(ReminderSchedule::class)->getSchedule();

        $recurringMessages = $schedule->getRecurringMessages();
        self::assertCount(1, $recurringMessages);
        $trigger = $recurringMessages[0]->getTrigger();
        $messages = self::messagesOf($recurringMessages[0]);
        self::assertCount(1, $messages);
        self::assertInstanceOf(CheckRemindersMessage::class, $messages[0]);
        self::assertSame('every 1 day', (string) $trigger);

        $firstRun = $trigger->getNextRunDate(new \DateTimeImmutable('2000-01-01')) ?? self::fail('The schedule never runs.');
        self::assertSame('07:00 Europe/Paris', $firstRun->format('H:i e'));
        self::assertEveryDayAt7($trigger, $firstRun);
    }

    public function testAMissedRunIsCaughtUpOnce(): void
    {
        self::bootKernel();
        $schedule = self::getContainer()->get(ReminderSchedule::class)->getSchedule();

        self::assertNotNull($schedule->getState(), 'The last run is remembered.');
        self::assertTrue($schedule->shouldProcessOnlyLastMissedRun(), 'A worker down for days checks once on restart.');
    }

    public function testTheTimezoneComesFromTheGardenTimezoneEnvVar(): void
    {
        $this->setEnvVar('GARDEN_TIMEZONE', 'America/New_York');
        self::bootKernel();

        $trigger = self::getContainer()->get(ReminderSchedule::class)->getSchedule()->getRecurringMessages()[0]->getTrigger();

        $firstRun = $trigger->getNextRunDate(new \DateTimeImmutable('2000-01-01')) ?? self::fail('The schedule never runs.');
        self::assertSame('07:00 America/New_York', $firstRun->format('H:i e'));
        self::assertEveryDayAt7($trigger, $firstRun);
    }

    /**
     * Over more than a year: both changes of daylight saving time keep 7:00, local time.
     */
    private static function assertEveryDayAt7(TriggerInterface $trigger, \DateTimeImmutable $firstRun): void
    {
        $run = $firstRun;
        for ($day = 1; $day <= 400; ++$day) {
            $next = $trigger->getNextRunDate($run) ?? self::fail('The schedule stopped.');
            self::assertSame($run->modify('+1 day')->format('Y-m-d') . ' 07:00', $next->format('Y-m-d H:i'));
            $run = $next;
        }
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
