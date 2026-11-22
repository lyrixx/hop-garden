<?php

namespace App\Tests\Integration\Reminder;

use App\Factory\CareSheetFactory;
use App\Factory\PlantFactory;
use App\Factory\PlantingFactory;
use App\Garden\Entity\Garden;
use App\Locale\Model\SupportedLocale;
use App\Mercure\Topics;
use App\Reminder\Message\CheckRemindersMessage;
use App\Reminder\Model\ReminderType;
use App\Reminder\NotificationRepository;
use App\Settings\Entity\Settings;
use App\Settings\SettingsRepository;
use App\Story\DefaultGardenStory;
use App\Tests\Utils\Mercure\RecordingHub;
use App\Tests\Utils\Mercure\TurboStream;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A member who never chose their language gets their reminders in the one of the application, and their toasts too:
 * the owner of the garden of the test (each member in theirs: ReminderCheckerTest). The recorded forecast of the demo
 * mode starts on the day of the clock: -3.2 °C, -8.5 °C, -12.4 °C.
 */
final class RemindersInThePreferredLocaleTest extends KernelTestCase
{
    use ClockSensitiveTrait;

    private const string FROST_IN_FRENCH = "Gel annoncé le mardi 1 décembre (-3,2\u{a0}°C)\u{a0}: rentrez Citronnier à l’abri. Jusqu’à -12,4\u{a0}°C le jeudi 3 décembre.";

    protected function setUp(): void
    {
        self::bootKernel();
        RecordingHub::reset();

        $lemonTree = PlantFactory::new()
            ->validated()
            ->withEnabledReminders(ReminderType::Frost, ReminderType::Pruning)
            ->create(['commonName' => 'Citronnier', 'careSheet' => CareSheetFactory::new(['minTemperatureC' => -3, 'frostSensitive' => true, 'pruningMonths' => [12]])])
        ;
        PlantingFactory::new()->of($lemonTree)->create();
        // The language of the application: the owner of the garden chose none
        $settings = new Settings();
        $settings->choosePreferredLocale(SupportedLocale::French);
        self::getContainer()->get(SettingsRepository::class)->add($settings);
        self::getContainer()->get(EntityManagerInterface::class)->flush();
    }

    public function testTheSchedulerNotifiesInThePreferredLocale(): void
    {
        self::assertNull(self::garden()->getOwner()->getPreferredLocale());
        self::mockTime('2026-12-01 07:00');

        self::getContainer()->get(MessageBusInterface::class)->dispatch(new CheckRemindersMessage());

        self::assertSame(
            [self::FROST_IN_FRENCH, 'C’est le moment de tailler Citronnier. Cut the faded flower heads back to the first pair of buds.'],
            $this->messages(),
            'Its own words, the advice of the care sheet as written.',
        );

        $toasts = array_map(static fn (string $update): array => TurboStream::parseAll($update), RecordingHub::published(self::getContainer()->get(Topics::class)->notifications(self::garden()->getOwner(), self::garden())));
        self::assertCount(2, $toasts);
        $frost = array_values(array_filter($toasts, static fn (array $streams): bool => 'Alerte gel' === $streams[0]->element()->filter('strong')->text()))[0][0]->element();
        self::assertSame(self::FROST_IN_FRENCH, $frost->filter('p')->text());
        self::assertSame('Voir Citronnier', $frost->filter('a')->text());
        self::assertSame('Fermer', $frost->filter('button')->attr('aria-label'));

        self::assertSame('en', self::getContainer()->get(TranslatorInterface::class)->getLocale(), 'Back to the default locale, for the next message.');
    }

    public function testTheCommandOfTheDemoToo(): void
    {
        $tester = new CommandTester((new Application(self::$kernel ?? throw new \LogicException()))->find('app:reminders:run'));
        $tester->execute(['--date' => '2026-12-01']);

        $tester->assertCommandIsSuccessful();
        self::assertContains(self::FROST_IN_FRENCH, $this->messages());
        self::assertStringContainsString('Alerte gel', implode("\n", RecordingHub::published(self::getContainer()->get(Topics::class)->notifications(self::garden()->getOwner(), self::garden()))));
    }

    /**
     * @return list<string>
     */
    private function messages(): array
    {
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        $garden = self::getContainer()->get(EntityManagerInterface::class)->find(Garden::class, self::garden()->getId()) ?? self::fail();

        return array_map(static fn ($notification): string => $notification->getMessage(), self::getContainer()->get(NotificationRepository::class)->findLatest($garden->getOwner(), $garden));
    }

    private static function garden(): Garden
    {
        $garden = DefaultGardenStory::get('garden');
        self::assertInstanceOf(Garden::class, $garden);

        return $garden;
    }
}
