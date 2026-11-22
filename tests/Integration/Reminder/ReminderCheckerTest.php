<?php

namespace App\Tests\Integration\Reminder;

use App\Garden\Model\Planting;
use App\Plant\Model\Plant;
use App\Reminder\Message\CheckRemindersMessage;
use App\Reminder\Model\ReminderType;
use App\Reminder\NotificationRepository;
use App\Reminder\Notifier\NotificationToastPublisher;
use App\Reminder\ReminderChecker;
use App\Tests\Factory\PlantFactory;
use App\Tests\Http\UnavailableDataSources;
use App\Tests\Mercure\RecordingHub;
use App\Tests\Mercure\TurboStream;
use App\Tests\Mercure\UnreachableHub;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The recorded forecast of the demo mode starts on the day of the clock: -3.2 °C, -8.5 °C, -12.4 °C.
 */
final class ReminderCheckerTest extends KernelTestCase
{
    use ClockSensitiveTrait;

    protected function setUp(): void
    {
        self::bootKernel();
        RecordingHub::reset();
    }

    public function testACalendarReminderFiresOncePerMonthOfTheCareSheet(): void
    {
        $plant = PlantFactory::validated('Rose', PlantFactory::careSheet(pruningMonths: [2, 3]));
        $plant->enableReminder(ReminderType::Pruning);
        $this->save($plant);

        self::assertSame(1, $this->check('2026-02-01 07:00'));
        self::assertSame(['Time to prune Rose. Cut the faded flower heads back to the first pair of buds.'], $this->messages());

        self::assertSame(0, $this->check('2026-02-02 07:00'), 'Once per month.');
        self::assertSame(1, $this->check('2026-03-01 07:00'));
        self::assertSame(0, $this->check('2026-04-01 07:00'), 'Not a pruning month.');
        self::assertSame(1, $this->check('2027-02-01 07:00'), 'Again the next year.');
    }

    public function testDisabledRemindersAndDraftsStaySilent(): void
    {
        $validated = PlantFactory::validated('Rose', PlantFactory::careSheet(pruningMonths: [3]));
        $validated->enableReminder(ReminderType::Pruning);
        $validated->disableReminder(ReminderType::Pruning);
        $this->save($validated);
        $this->save(PlantFactory::draft('Draft', PlantFactory::careSheet(pruningMonths: [3])));

        self::assertSame(0, $this->check('2026-03-01 07:00'));
    }

    public function testFrostBelowTheHardinessWithItsMargin(): void
    {
        $hardy = PlantFactory::validated('Hardy', PlantFactory::careSheet(minTemperatureC: -15));
        $hardy->enableReminder(ReminderType::Frost);
        $this->save($hardy);
        $tender = PlantFactory::validated('Lavender', PlantFactory::careSheet(minTemperatureC: -12));
        $tender->enableReminder(ReminderType::Frost);
        $this->save($tender);

        self::assertSame(1, $this->check('2026-12-01 07:00'));
        // -12 °C + 2 °C: the -12.4 °C of Thursday only
        self::assertSame(['Frost expected on Thursday 3 December (-12.4 °C): cover Lavender.'], $this->messages());
    }

    public function testAnyFrostWarnsAboutAFrostSensitivePlant(): void
    {
        $lemonTree = PlantFactory::validated('Lemon tree', PlantFactory::careSheet(minTemperatureC: -3, frostSensitive: true));
        $lemonTree->enableReminder(ReminderType::Frost);
        $this->save($lemonTree);

        self::assertSame(1, $this->check('2026-12-01 07:00'));
        self::assertSame(['Frost expected on Tuesday 1 December (-3.2 °C): bring Lemon tree indoors. Down to -12.4 °C on Thursday 3 December.'], $this->messages());
    }

    public function testOneFrostAlertPerColdEpisode(): void
    {
        $lemonTree = PlantFactory::validated('Lemon tree', PlantFactory::careSheet(frostSensitive: true));
        $lemonTree->enableReminder(ReminderType::Frost);
        $this->save($lemonTree);

        self::assertSame(1, $this->check('2026-12-01 07:00'));
        self::assertSame(0, $this->check('2026-12-02 07:00'), 'The same cold episode goes on.');
        self::assertSame(0, $this->check('2026-12-05 07:00'), 'Still contiguous with the announced frost days.');
        self::assertSame(1, $this->check('2026-12-20 07:00'), 'A new cold episode, after a gap.');
    }

    public function testWithoutForecastOnlyTheFrostRemindersWait(): void
    {
        UnavailableDataSources::openMeteo(self::getContainer());
        $rose = PlantFactory::validated('Rose', PlantFactory::careSheet(frostSensitive: true, pruningMonths: [12]));
        $rose->enableReminder(ReminderType::Pruning);
        $rose->enableReminder(ReminderType::Frost);
        $this->save($rose);

        self::assertSame(1, $this->check('2026-12-01 07:00'));
        self::assertSame(['Time to prune Rose. Cut the faded flower heads back to the first pair of buds.'], $this->messages());
    }

    public function testNoForecastIsAskedWithoutFrostReminder(): void
    {
        UnavailableDataSources::openMeteo(self::getContainer());
        $rose = PlantFactory::validated('Rose', PlantFactory::careSheet(pruningMonths: [12]));
        $rose->enableReminder(ReminderType::Pruning);
        $this->save($rose);

        self::assertSame(1, $this->check('2026-12-01 07:00'));
    }

    public function testTheToastIsPushedOnceTheNotificationIsStored(): void
    {
        $this->save(self::withReminder(PlantFactory::validated('Rose', PlantFactory::careSheet(pruningMonths: [12])), ReminderType::Pruning));
        $this->check('2026-12-01 07:00');
        $lemonTree = PlantFactory::validated('Lemon tree', PlantFactory::careSheet(frostSensitive: true));
        $lemonTree->enableReminder(ReminderType::Frost);
        $this->save($lemonTree);
        $terrace = Planting::forValidatedPlant($lemonTree);
        self::getContainer()->get(EntityManagerInterface::class)->persist($terrace);
        RecordingHub::reset();

        self::getContainer()->get(ReminderChecker::class)->check(self::mockTime('2026-12-01 07:00')->now());
        self::assertSame([], RecordingHub::published(NotificationToastPublisher::TOPIC), 'Not stored yet: no toast.');

        self::getContainer()->get(EntityManagerInterface::class)->flush();

        $toasts = RecordingHub::published(NotificationToastPublisher::TOPIC);
        self::assertCount(1, $toasts);
        [$toastStream, $badgeStream] = TurboStream::parseAll($toasts[0]);
        self::assertSame(['append', 'toasts'], [$toastStream->action, $toastStream->target]);
        $toast = $toastStream->element();
        self::assertSame('toast-' . self::getContainer()->get(NotificationRepository::class)->findLatest()[0]->getId(), $toast->attr('id'), 'Shown once, even if it comes twice.');
        self::assertSame('Frost expected on Tuesday 1 December (-3.2 °C): bring Lemon tree indoors. Down to -12.4 °C on Thursday 3 December.', $toast->filter('p')->text());
        self::assertSame('card toast toast-frost', $toast->attr('class'));
        self::assertSame('Frost alert', $toast->filter('strong')->text());
        self::assertSame('See Lemon tree', $toast->filter('a')->text());
        self::assertSame('/garden/' . $terrace->getId(), $toast->filter('a')->attr('href'), 'My lemon tree, in the garden.');
        self::assertSame(['replace', 'notification-badge'], [$badgeStream->action, $badgeStream->target]);
        self::assertSame('2', $badgeStream->element()->text(), 'With the pruning reminder, still unread.');

        self::getContainer()->get(EntityManagerInterface::class)->flush();
        self::assertCount(1, RecordingHub::published(NotificationToastPublisher::TOPIC), 'Pushed once.');
    }

    public function testAHubDownLosesTheToastOnly(): void
    {
        self::getContainer()->set('mercure.hub.default', new UnreachableHub());
        $this->save(self::withReminder(PlantFactory::validated('Rose', PlantFactory::careSheet(pruningMonths: [12])), ReminderType::Pruning));

        self::assertSame(1, $this->check('2026-12-01 07:00'));

        self::assertSame([], RecordingHub::published(NotificationToastPublisher::TOPIC));
        self::assertSame(['Time to prune Rose. Cut the faded flower heads back to the first pair of buds.'], $this->messages(), 'Shown on the next page load.');
    }

    public function testTheSchedulerMessageChecksTheRemindersOfToday(): void
    {
        $this->save(self::withReminder(PlantFactory::validated('Rose', PlantFactory::careSheet(pruningMonths: [3])), ReminderType::Pruning));
        self::mockTime('2026-03-01 07:00');

        self::getContainer()->get(MessageBusInterface::class)->dispatch(new CheckRemindersMessage());

        self::getContainer()->get(EntityManagerInterface::class)->clear();
        self::assertSame(['Time to prune Rose. Cut the faded flower heads back to the first pair of buds.'], $this->messages(), 'Handled at once, and flushed.');
        self::assertSame('2026-03-01 07:00', self::getContainer()->get(NotificationRepository::class)->findLatest()[0]->getCreatedAt()->format('Y-m-d H:i'));
    }

    public function testTheCommandSimulatesADate(): void
    {
        $plant = PlantFactory::validated('Rose', PlantFactory::careSheet(pruningMonths: [3]));
        $plant->enableReminder(ReminderType::Pruning);
        $this->save($plant);

        $tester = new CommandTester((new Application(self::$kernel ?? throw new \LogicException()))->find('app:reminders:run'));
        $tester->execute(['--date' => '2026-03-14']);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('1 notification(s) sent for Saturday 14 March 2026.', $tester->getDisplay());
        self::assertSame('2026-03-14 07:00', self::getContainer()->get(NotificationRepository::class)->findLatest()[0]->getCreatedAt()->format('Y-m-d H:i'));

        $tester->execute(['--date' => '14/03/2026']);
        self::assertSame(2, $tester->getStatusCode());
        self::assertStringContainsString('Invalid date "14/03/2026", expected YYYY-MM-DD.', $tester->getDisplay());

        $tester->execute(['--date' => '2026-02-30']);
        self::assertSame(2, $tester->getStatusCode(), 'A day that does not exist.');
    }

    public function testWithoutDateTheCommandChecksNow(): void
    {
        $this->save(self::withReminder(PlantFactory::validated('Rose', PlantFactory::careSheet(pruningMonths: [3])), ReminderType::Pruning));
        self::mockTime('2026-03-02 18:45');

        $tester = new CommandTester((new Application(self::$kernel ?? throw new \LogicException()))->find('app:reminders:run'));
        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('1 notification(s) sent for Monday 2 March 2026.', $tester->getDisplay());
        self::assertSame('2026-03-02 18:45', self::getContainer()->get(NotificationRepository::class)->findLatest()[0]->getCreatedAt()->format('Y-m-d H:i'));
    }

    private function check(string $now): int
    {
        $sent = self::getContainer()->get(ReminderChecker::class)->check(self::mockTime($now)->now());
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        return $sent;
    }

    /**
     * @return list<string>
     */
    private function messages(): array
    {
        return array_map(static fn ($notification): string => $notification->getMessage(), self::getContainer()->get(NotificationRepository::class)->findLatest());
    }

    private static function withReminder(Plant $plant, ReminderType $type): Plant
    {
        $plant->enableReminder($type);

        return $plant;
    }

    private function save(Plant $plant): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($plant);
        $entityManager->flush();
    }
}
