<?php

namespace App\Tests\Integration\Reminder;

use App\Factory\CareSheetFactory;
use App\Factory\GardenFactory;
use App\Factory\PlantFactory;
use App\Factory\PlantingFactory;
use App\Factory\ReminderFactory;
use App\Factory\UserFactory;
use App\Garden\Entity\Garden;
use App\Garden\Entity\GardenLocation;
use App\Locale\Model\SupportedLocale;
use App\Mercure\Topics;
use App\Reminder\Entity\Notification;
use App\Reminder\Message\CheckRemindersMessage;
use App\Reminder\Model\ReminderType;
use App\Reminder\NotificationRepository;
use App\Reminder\ReminderChecker;
use App\Story\DefaultGardenStory;
use App\Tests\Utils\Garden\GardenPathsTrait;
use App\Tests\Utils\Http\UnavailableDataSources;
use App\Tests\Utils\Mercure\RecordingHub;
use App\Tests\Utils\Mercure\TurboStream;
use App\Tests\Utils\Mercure\UnreachableHub;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The recorded forecast of the demo mode starts on the day of the clock: -3.2 °C, -8.5 °C, -12.4 °C. The garden of the
 * test is in Paris, its owner the only member, in English, unless told.
 */
final class ReminderCheckerTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use GardenPathsTrait;

    protected function setUp(): void
    {
        self::bootKernel();
        RecordingHub::reset();
    }

    public function testACalendarReminderFiresOncePerMonthOfTheCareSheet(): void
    {
        PlantFactory::new()->validated()->withEnabledReminders(ReminderType::Pruning)->create(['commonName' => 'Rose', 'careSheet' => CareSheetFactory::new(['pruningMonths' => [2, 3]])]);

        self::assertSame(1, $this->check('2026-02-01 07:00'));
        self::assertSame(['Time to prune Rose. Cut the faded flower heads back to the first pair of buds.'], $this->messages());

        self::assertSame(0, $this->check('2026-02-02 07:00'), 'Once per month.');
        self::assertSame(1, $this->check('2026-03-01 07:00'));
        self::assertSame(0, $this->check('2026-04-01 07:00'), 'Not a pruning month.');
        self::assertSame(1, $this->check('2027-02-01 07:00'), 'Again the next year.');
    }

    public function testDisabledRemindersAndDraftsStaySilent(): void
    {
        ReminderFactory::new()->disabled()->create([
            'plant' => PlantFactory::new()->validated()->with(['commonName' => 'Rose', 'careSheet' => CareSheetFactory::new(['pruningMonths' => [3]])]),
            'type' => ReminderType::Pruning,
        ]);
        PlantFactory::createOne(['commonName' => 'Draft', 'careSheet' => CareSheetFactory::new(['pruningMonths' => [3]])]);

        self::assertSame(0, $this->check('2026-03-01 07:00'));
    }

    public function testFrostBelowTheHardinessWithItsMargin(): void
    {
        PlantFactory::new()->validated()->withEnabledReminders(ReminderType::Frost)->create(['commonName' => 'Hardy', 'careSheet' => CareSheetFactory::new(['minTemperatureC' => -15])]);
        PlantFactory::new()->validated()->withEnabledReminders(ReminderType::Frost)->create(['commonName' => 'Lavender', 'careSheet' => CareSheetFactory::new(['minTemperatureC' => -12])]);

        self::assertSame(1, $this->check('2026-12-01 07:00'));
        // -12 °C + 2 °C: the -12.4 °C of Thursday only
        self::assertSame(['Frost expected on Thursday 3 December (-12.4 °C): cover Lavender.'], $this->messages());
    }

    public function testAnyFrostWarnsAboutAFrostSensitivePlant(): void
    {
        PlantFactory::new()->validated()->withEnabledReminders(ReminderType::Frost)->create(['commonName' => 'Lemon tree', 'careSheet' => CareSheetFactory::new(['minTemperatureC' => -3, 'frostSensitive' => true])]);

        self::assertSame(1, $this->check('2026-12-01 07:00'));
        self::assertSame(['Frost expected on Tuesday 1 December (-3.2 °C): bring Lemon tree indoors. Down to -12.4 °C on Thursday 3 December.'], $this->messages());
    }

    public function testOneFrostAlertPerColdEpisode(): void
    {
        PlantFactory::new()->validated()->withEnabledReminders(ReminderType::Frost)->create(['commonName' => 'Lemon tree', 'careSheet' => CareSheetFactory::new(['frostSensitive' => true])]);

        self::assertSame(1, $this->check('2026-12-01 07:00'));
        self::assertSame(0, $this->check('2026-12-02 07:00'), 'The same cold episode goes on.');
        self::assertSame(0, $this->check('2026-12-05 07:00'), 'Still contiguous with the announced frost days.');
        self::assertSame(1, $this->check('2026-12-20 07:00'), 'A new cold episode, after a gap.');
    }

    public function testWithoutForecastOnlyTheFrostRemindersWait(): void
    {
        UnavailableDataSources::openMeteo(self::getContainer());
        PlantFactory::new()->validated()->withEnabledReminders(ReminderType::Pruning, ReminderType::Frost)->create(['commonName' => 'Rose', 'careSheet' => CareSheetFactory::new(['frostSensitive' => true, 'pruningMonths' => [12]])]);

        self::assertSame(1, $this->check('2026-12-01 07:00'));
        self::assertSame(['Time to prune Rose. Cut the faded flower heads back to the first pair of buds.'], $this->messages());
    }

    public function testNoForecastIsAskedWithoutFrostReminder(): void
    {
        UnavailableDataSources::openMeteo(self::getContainer());
        PlantFactory::new()->validated()->withEnabledReminders(ReminderType::Pruning)->create(['commonName' => 'Rose', 'careSheet' => CareSheetFactory::new(['pruningMonths' => [12]])]);

        self::assertSame(1, $this->check('2026-12-01 07:00'));
    }

    public function testTheToastIsPushedOnceTheNotificationIsStored(): void
    {
        PlantFactory::new()->validated()->withEnabledReminders(ReminderType::Pruning)->create(['commonName' => 'Rose', 'careSheet' => CareSheetFactory::new(['pruningMonths' => [12]])]);
        $this->check('2026-12-01 07:00');
        $lemonTree = PlantFactory::new()->validated()->withEnabledReminders(ReminderType::Frost)->create(['commonName' => 'Lemon tree', 'careSheet' => CareSheetFactory::new(['frostSensitive' => true])]);
        $terrace = PlantingFactory::new()->of($lemonTree)->create();
        RecordingHub::reset();

        self::getContainer()->get(ReminderChecker::class)->check(self::garden(), self::mockTime('2026-12-01 07:00')->now());
        self::assertSame([], RecordingHub::published($this->notificationsTopic()), 'Not stored yet: no toast.');

        self::getContainer()->get(EntityManagerInterface::class)->flush();

        $toasts = RecordingHub::published($this->notificationsTopic());
        self::assertTrue(RecordingHub::updates($this->notificationsTopic())[0]->isPrivate(), 'To the member only.');
        self::assertCount(1, $toasts);
        [$toastStream, $badgeStream] = TurboStream::parseAll($toasts[0]);
        self::assertSame(['append', 'toasts'], [$toastStream->action, $toastStream->target]);
        $toast = $toastStream->element();
        self::assertSame('toast-' . $this->latest()[0]->getId(), $toast->attr('id'), 'Shown once, even if it comes twice.');
        self::assertSame('Frost expected on Tuesday 1 December (-3.2 °C): bring Lemon tree indoors. Down to -12.4 °C on Thursday 3 December.', $toast->filter('p')->text());
        self::assertSame('card toast toast-frost', $toast->attr('class'));
        self::assertSame('Frost alert', $toast->filter('strong')->text());
        self::assertSame('See Lemon tree', $toast->filter('a')->text());
        self::assertSame(self::pathOf($terrace), $toast->filter('a')->attr('href'), 'My lemon tree, in the garden.');
        self::assertSame(['replace', 'notification-badge'], [$badgeStream->action, $badgeStream->target]);
        self::assertSame('2', $badgeStream->element()->text(), 'With the pruning reminder, still unread.');

        self::getContainer()->get(EntityManagerInterface::class)->flush();
        self::assertCount(1, RecordingHub::published($this->notificationsTopic()), 'Pushed once.');
    }

    public function testEveryMemberIsNotifiedInTheirLanguage(): void
    {
        $alice = UserFactory::new()->approved()->create(['preferredLocale' => SupportedLocale::English]);
        $bob = UserFactory::new()->approved()->create(['preferredLocale' => SupportedLocale::French]);
        $carol = UserFactory::new()->approved()->create(['preferredLocale' => SupportedLocale::English]);
        $allotment = GardenFactory::new()->ownedBy($alice)->withMembers($bob)->create();
        GardenFactory::new()->ownedBy($carol)->create();
        PlantFactory::new()->validated()->withEnabledReminders(ReminderType::Frost)->create(['garden' => $allotment, 'commonName' => 'Lemon tree', 'careSheet' => CareSheetFactory::new(['frostSensitive' => true])]);

        self::assertSame(1, $this->check('2026-12-01 07:00', $allotment), 'One reminder due, sent to each member.');

        $repository = self::getContainer()->get(NotificationRepository::class);
        self::assertSame(['Frost expected on Tuesday 1 December (-3.2 °C): bring Lemon tree indoors. Down to -12.4 °C on Thursday 3 December.'], self::messagesOf($repository->findLatest($alice, $allotment)));
        self::assertSame(["Gel annoncé le mardi 1 décembre (-3,2\u{a0}°C)\u{a0}: rentrez Lemon tree à l’abri. Jusqu’à -12,4\u{a0}°C le jeudi 3 décembre."], self::messagesOf($repository->findLatest($bob, $allotment)));

        $topics = self::getContainer()->get(Topics::class);
        $toastOf = static fn (string $topic) => TurboStream::parseAll(RecordingHub::published($topic)[0] ?? self::fail('No toast on ' . $topic))[0]->element();
        self::assertSame('Frost alert', $toastOf($topics->notifications($alice, $allotment))->filter('strong')->text());
        self::assertSame('Alerte gel', $toastOf($topics->notifications($bob, $allotment))->filter('strong')->text(), 'Rendered in the language of Bob.');
        self::assertSame('Voir Lemon tree', $toastOf($topics->notifications($bob, $allotment))->filter('a')->text());
        self::assertSame([$topics->notifications($alice, $allotment), $topics->notifications($bob, $allotment)], RecordingHub::topics(), 'Nothing to Carol, nor to anyone else.');
    }

    public function testAHubDownLosesTheToastOnly(): void
    {
        self::getContainer()->set('mercure.hub.default', new UnreachableHub());
        PlantFactory::new()->validated()->withEnabledReminders(ReminderType::Pruning)->create(['commonName' => 'Rose', 'careSheet' => CareSheetFactory::new(['pruningMonths' => [12]])]);

        self::assertSame(1, $this->check('2026-12-01 07:00'));

        self::assertSame([], RecordingHub::published($this->notificationsTopic()));
        self::assertSame(['Time to prune Rose. Cut the faded flower heads back to the first pair of buds.'], $this->messages(), 'Shown on the next page load.');
    }

    public function testTheSchedulerMessageChecksTheRemindersOfToday(): void
    {
        PlantFactory::new()->validated()->withEnabledReminders(ReminderType::Pruning)->create(['commonName' => 'Rose', 'careSheet' => CareSheetFactory::new(['pruningMonths' => [3]])]);
        // 7:00 in Paris
        self::mockTime('2026-03-01 06:00');

        self::getContainer()->get(MessageBusInterface::class)->dispatch(new CheckRemindersMessage());

        self::getContainer()->get(EntityManagerInterface::class)->clear();
        self::assertSame(['Time to prune Rose. Cut the faded flower heads back to the first pair of buds.'], $this->messages(), 'Handled at once, and flushed.');
        self::assertSame('2026-03-01 06:00', $this->latest()[0]->getCreatedAt()->format('Y-m-d H:i'));
    }

    /**
     * Every hour: a garden from 7:00 in its timezone, once a day, a missed hour caught up later that day.
     */
    public function testTheSchedulerChecksEachGardenOnceADayFrom7InItsTimezone(): void
    {
        $paris = self::garden();
        $newYork = GardenFactory::new()->at(new GardenLocation('New York, United States', 40.71427, -74.00597, 'America/New_York'))->create();
        foreach ([$paris, $newYork] as $garden) {
            PlantFactory::new()->validated()->withEnabledReminders(ReminderType::Frost)->create(['garden' => $garden, 'commonName' => 'Lemon tree', 'careSheet' => CareSheetFactory::new(['frostSensitive' => true])]);
        }
        $bus = self::getContainer()->get(MessageBusInterface::class);
        $checkedOn = static function (Garden $garden): ?string {
            $repository = self::getContainer()->get(NotificationRepository::class);

            return [] !== $repository->findLatest($garden->getOwner(), $garden) ? $garden->getRemindersCheckedOn()?->format('Y-m-d') : null;
        };

        // 6:00 in Paris, midnight in New York
        self::mockTime('2026-12-01 05:00');
        $bus->dispatch(new CheckRemindersMessage());
        self::assertSame([null, null], [$checkedOn($paris), $checkedOn($newYork)], 'Before 7:00 everywhere.');

        // 9:00 in Paris (the worker was down at 7:00), 3:00 in New York
        self::mockTime('2026-12-01 08:00');
        $bus->dispatch(new CheckRemindersMessage());
        self::assertSame(['2026-12-01', null], [$checkedOn($paris), $checkedOn($newYork)]);

        // 7:00 in New York
        self::mockTime('2026-12-01 12:00');
        $bus->dispatch(new CheckRemindersMessage());
        self::assertSame(['2026-12-01', '2026-12-01'], [$checkedOn($paris), $checkedOn($newYork)]);
        self::assertCount(2, RecordingHub::topics(), 'Each garden once.');
    }

    public function testTheCommandSimulatesADate(): void
    {
        PlantFactory::new()->validated()->withEnabledReminders(ReminderType::Pruning)->create(['commonName' => 'Rose', 'careSheet' => CareSheetFactory::new(['pruningMonths' => [3]])]);

        $tester = new CommandTester((new Application(self::$kernel ?? throw new \LogicException()))->find('app:reminders:run'));
        $tester->execute(['--date' => '2026-03-14']);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('1 reminder(s) due in 1 garden(s), sent to each of their members.', $tester->getDisplay());
        // 7:00 in Paris
        self::assertSame('2026-03-14 06:00', $this->latest()[0]->getCreatedAt()->format('Y-m-d H:i'));

        $tester->execute(['--date' => '14/03/2026']);
        self::assertSame(2, $tester->getStatusCode());
        self::assertStringContainsString('Invalid date "14/03/2026", expected YYYY-MM-DD.', $tester->getDisplay());

        $tester->execute(['--date' => '2026-02-30']);
        self::assertSame(2, $tester->getStatusCode(), 'A day that does not exist.');
    }

    public function testWithoutDateTheCommandChecksNow(): void
    {
        PlantFactory::new()->validated()->withEnabledReminders(ReminderType::Pruning)->create(['commonName' => 'Rose', 'careSheet' => CareSheetFactory::new(['pruningMonths' => [3]])]);
        self::mockTime('2026-03-02 18:45');

        $tester = new CommandTester((new Application(self::$kernel ?? throw new \LogicException()))->find('app:reminders:run'));
        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('1 reminder(s) due in 1 garden(s), sent to each of their members.', $tester->getDisplay());
        self::assertSame('2026-03-02 18:45', $this->latest()[0]->getCreatedAt()->format('Y-m-d H:i'));
    }

    public function testTheCommandChecksOneGardenWhenTold(): void
    {
        $elsewhere = GardenFactory::createOne();
        PlantFactory::new()->validated()->withEnabledReminders(ReminderType::Pruning)->create(['commonName' => 'Rose', 'careSheet' => CareSheetFactory::new(['pruningMonths' => [3]])]);
        PlantFactory::new()->validated()->withEnabledReminders(ReminderType::Pruning)->create(['garden' => $elsewhere, 'commonName' => 'Rose', 'careSheet' => CareSheetFactory::new(['pruningMonths' => [3]])]);
        self::mockTime('2026-03-02 18:45');

        $tester = new CommandTester((new Application(self::$kernel ?? throw new \LogicException()))->find('app:reminders:run'));
        $tester->execute(['--garden' => $elsewhere->getSlug()]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('1 reminder(s) due in 1 garden(s)', $tester->getDisplay());
        self::assertSame([], $this->messages(), 'Not the garden of the test.');

        $tester->execute(['--garden' => 'no-such-garden']);
        self::assertSame(2, $tester->getStatusCode());
        self::assertStringContainsString('No garden "no-such-garden".', $tester->getDisplay());
    }

    private function check(string $now, ?Garden $garden = null): int
    {
        $sent = self::getContainer()->get(ReminderChecker::class)->check($garden ?? self::garden(), self::mockTime($now)->now());
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        return $sent;
    }

    /**
     * @return list<string> of the owner of the garden of the test
     */
    private function messages(): array
    {
        return self::messagesOf($this->latest());
    }

    /**
     * @return list<Notification>
     */
    private function latest(): array
    {
        return self::getContainer()->get(NotificationRepository::class)->findLatest(self::garden()->getOwner(), self::garden());
    }

    /**
     * @param list<Notification> $notifications
     *
     * @return list<string>
     */
    private static function messagesOf(array $notifications): array
    {
        return array_map(static fn (Notification $notification): string => $notification->getMessage(), $notifications);
    }

    private function notificationsTopic(): string
    {
        return self::getContainer()->get(Topics::class)->notifications(self::garden()->getOwner(), self::garden());
    }

    private static function garden(): Garden
    {
        $garden = DefaultGardenStory::get('garden');
        self::assertInstanceOf(Garden::class, $garden);

        return $garden;
    }
}
