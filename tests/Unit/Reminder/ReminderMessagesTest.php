<?php

namespace App\Tests\Unit\Reminder;

use App\DataSource\OpenMeteo\Model\DailyMinimum;
use App\Plant\Entity\CareSheet;
use App\Plant\Model\Exposure;
use App\Plant\Model\Source;
use App\Plant\Model\Watering;
use App\Reminder\Entity\Reminder;
use App\Reminder\Model\FrostRisk;
use App\Reminder\Model\ReminderType;
use App\Reminder\ReminderMessages;
use App\Tests\Utils\Factory\PlantFactory;
use PHPUnit\Framework\TestCase;

final class ReminderMessagesTest extends TestCase
{
    public function testTheCalendarMessagesGiveTheAdviceOfTheCareSheet(): void
    {
        $messages = new ReminderMessages();

        self::assertSame('Time to prune Rose. Cut the faded flower heads back to the first pair of buds.', $messages->forCalendar(self::reminder(ReminderType::Pruning)));
        self::assertSame('Time to deadhead Rose: cut the faded flowers.', $messages->forCalendar(self::reminder(ReminderType::Deadheading)));
        self::assertSame('Time to take cuttings of Rose. Take softwood cuttings of 10 cm.', $messages->forCalendar(self::reminder(ReminderType::Cutting)));
    }

    public function testWithoutAdviceTheMessageEndsCleanly(): void
    {
        $careSheet = new CareSheet(-15, false, Exposure::Sun, Watering::Medium, [3], [], [6], '', '', [], [new Source(Source::LLM_KNOWLEDGE)]);
        $plant = PlantFactory::validated('Rose', $careSheet);

        self::assertSame('Time to prune Rose.', (new ReminderMessages())->forCalendar($plant->enableReminder(ReminderType::Pruning)));
    }

    public function testAFrostReminderIsNotACalendarOne(): void
    {
        $this->expectException(\LogicException::class);

        (new ReminderMessages())->forCalendar(self::reminder(ReminderType::Frost));
    }

    public function testAFrostSensitivePlantIsBroughtIndoors(): void
    {
        $reminder = self::reminder(ReminderType::Frost, frostSensitive: true);

        self::assertSame(
            'Frost expected on Tuesday 1 December (-3.2 °C): bring Rose indoors. Down to -12.4 °C on Thursday 3 December.',
            (new ReminderMessages())->forFrost($reminder, self::risk(['2026-12-01' => -3.2, '2026-12-02' => -8.5, '2026-12-03' => -12.4])),
        );
    }

    public function testAHardyPlantIsCoveredAndTheFirstDayIsTheColdest(): void
    {
        $reminder = self::reminder(ReminderType::Frost);

        self::assertSame(
            'Frost expected on Thursday 3 December (-17.0 °C): cover Rose.',
            (new ReminderMessages())->forFrost($reminder, self::risk(['2026-12-03' => -17.0, '2026-12-04' => -14.0])),
        );
    }

    private static function reminder(ReminderType $type, bool $frostSensitive = false): Reminder
    {
        return PlantFactory::validated('Rose', PlantFactory::careSheet(frostSensitive: $frostSensitive))->enableReminder($type);
    }

    /**
     * @param non-empty-array<string, float> $minimums
     */
    private static function risk(array $minimums): FrostRisk
    {
        $days = [];
        foreach ($minimums as $date => $minimum) {
            $days[] = new DailyMinimum(new \DateTimeImmutable($date), $minimum);
        }

        return new FrostRisk(-13.0, $days);
    }
}
