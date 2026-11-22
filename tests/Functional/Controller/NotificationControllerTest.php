<?php

namespace App\Tests\Functional\Controller;

use App\DataSource\DataSourceCache;
use App\DataSource\OpenMeteo\OpenMeteoClient;
use App\Factory\CareSheetFactory;
use App\Factory\NotificationFactory;
use App\Factory\PlantFactory;
use App\Factory\PlantingFactory;
use App\Factory\ReminderFactory;
use App\Reminder\Model\ReminderType;
use App\Reminder\NotificationRepository;
use App\Tests\Utils\Http\UnavailableDataSources;
use App\Tests\Utils\Security\LogInTrait;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;

final class NotificationControllerTest extends WebTestCase
{
    use ClockSensitiveTrait;
    use LogInTrait;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->logIn($this->client);
    }

    public function testNothingYet(): void
    {
        self::mockTime('2026-11-22 09:30');

        $crawler = $this->client->request('GET', '/notifications');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.coming-up .reminder-empty', 'No reminder coming up.');
        self::assertSame(
            [
                ['Sunday 22 November', '-3.2 °C', 'No plant at risk'],
                ['Monday 23 November', '-8.5 °C', 'No plant at risk'],
                ['Tuesday 24 November', '-12.4 °C', 'No plant at risk'],
            ],
            self::forecastDays($crawler),
            'The recorded cold snap, from today, on the days of the garden.',
        );
        self::assertSelectorTextContains('.callout', 'Nothing yet. Reminders are checked every day at 7:00.');
        self::assertSelectorExists('.nav .badge[hidden]');
    }

    public function testComingUpListsTheEnabledRemindersByDate(): void
    {
        self::mockTime('2026-11-22 09:30');
        $rose = PlantFactory::new()
            ->validated()
            ->withEnabledReminders(ReminderType::Pruning, ReminderType::Cutting, ReminderType::Deadheading, ReminderType::Frost)
            ->create(['commonName' => 'Rose', 'careSheet' => CareSheetFactory::new(['minTemperatureC' => -20, 'pruningMonths' => [2], 'deadheadingMonths' => [7], 'cuttingMonths' => [12]])])
        ;
        $lemonTree = PlantFactory::new()
            ->validated()
            ->withEnabledReminders(ReminderType::Frost)
            ->create(['commonName' => 'Lemon tree', 'careSheet' => CareSheetFactory::new(['minTemperatureC' => -3, 'frostSensitive' => true, 'pruningMonths' => [12]])])
        ;
        ReminderFactory::new()->disabled()->create(['plant' => $lemonTree, 'type' => ReminderType::Pruning]);
        $roseByTheGate = PlantingFactory::new()->of($rose)->create();
        PlantingFactory::new()->of($lemonTree)->many(2)->create();

        $crawler = $this->client->request('GET', '/notifications');

        self::assertResponseIsSuccessful();
        self::assertSame(
            [
                ['Frost alert', 'Lemon tree', '/plants/' . $lemonTree->getId() . '#in-your-garden', 'Sunday 22 November, down to -12.4 °C'],
                ['Cuttings', 'Rose', '/garden/' . $roseByTheGate->getId(), 'Tuesday 1 December'],
                ['Pruning', 'Rose', '/garden/' . $roseByTheGate->getId(), 'Monday 1 February'],
            ],
            $crawler->filter('.coming-up .upcoming')->each(static fn (Crawler $upcoming): array => [
                $upcoming->filter('.tag')->text(),
                $upcoming->filter('a')->text(),
                $upcoming->filter('a')->attr('href'),
                $upcoming->filter('.meta')->text(),
            ]),
            'By date: the frost of the forecast first. The deadheading of July is too far, the hardy rose is not at risk, '
            . 'the disabled pruning stays out. The lemon tree has two plants: its species lists them.',
        );
    }

    public function testComingUpIsEmptyWithoutAnEnabledReminder(): void
    {
        self::mockTime('2026-11-22 09:30');
        PlantingFactory::new()->of(PlantFactory::new()->validated()->create(['commonName' => 'Rose', 'careSheet' => CareSheetFactory::new(['pruningMonths' => [12]])]))->create();

        $this->client->request('GET', '/notifications');

        self::assertResponseIsSuccessful();
        self::assertSelectorCount(0, '.coming-up .upcoming');
        self::assertSelectorTextContains('.coming-up .reminder-empty', 'No reminder coming up. Turn reminders on from the card of a species.');
    }

    public function testTheFrostForecastListsThePlantsAtRiskEachDay(): void
    {
        self::mockTime('2026-11-22 09:30');
        $hydrangea = PlantFactory::new()->validated()->create(['careSheet' => CareSheetFactory::new(['minTemperatureC' => -10])]);
        $lemonTree = PlantFactory::new()->validated()->create(['commonName' => 'Lemon tree', 'careSheet' => CareSheetFactory::new(['minTemperatureC' => -3, 'frostSensitive' => true])]);
        $hydrangeaInTheShade = PlantingFactory::new()->of($hydrangea)->create();
        PlantingFactory::new()->of(PlantFactory::new()->validated()->create(['commonName' => 'Rose', 'careSheet' => CareSheetFactory::new(['minTemperatureC' => -20])]))->create();
        PlantFactory::createOne(['commonName' => 'Olive tree', 'careSheet' => CareSheetFactory::new(['minTemperatureC' => -8])]);

        $crawler = $this->client->request('GET', '/notifications');

        self::assertResponseIsSuccessful();
        self::assertSame(
            [
                ['Sunday 22 November', '-3.2 °C', 'At risk: Lemon tree'],
                ['Monday 23 November', '-8.5 °C', 'At risk: Bigleaf hydrangea Lemon tree'],
                ['Tuesday 24 November', '-12.4 °C', 'At risk: Bigleaf hydrangea Lemon tree'],
            ],
            self::forecastDays($crawler),
            'Colder than the hardiness plus 2 °C, or 0 °C for the frost-sensitive lemon tree, frost reminder or not. '
            . 'The rose is hardy enough, the draft is not in the garden yet.',
        );
        self::assertSelectorCount(3, '.forecast-day.is-at-risk');
        self::assertSame(
            ['/plants/' . $lemonTree->getId(), '/garden/' . $hydrangeaInTheShade->getId()],
            array_values(array_unique($crawler->filter('.forecast-plants a')->each(static fn (Crawler $link): string => (string) $link->attr('href')))),
            'My plant, or the species when no plant of the garden follows its care sheet.',
        );
        self::assertSelectorTextContains('.frost-forecast .meta', 'Weather data by Open-Meteo.com (CC BY 4.0)');
    }

    public function testNoPlantAtRiskWhenTheForecastStaysAboveTheirThreshold(): void
    {
        self::mockTime('2026-11-22 09:30');
        self::forecast(['2026-11-22' => 4.5, '2026-11-23' => 0.0, '2026-11-24' => 6.1]);
        $lemonTree = PlantFactory::new()->validated()->withEnabledReminders(ReminderType::Frost)->create(['commonName' => 'Lemon tree', 'careSheet' => CareSheetFactory::new(['minTemperatureC' => -3, 'frostSensitive' => true])]);
        PlantingFactory::new()->of($lemonTree)->create();

        $crawler = $this->client->request('GET', '/notifications');

        self::assertResponseIsSuccessful();
        self::assertSame(
            [
                ['Sunday 22 November', '4.5 °C', 'No plant at risk'],
                ['Monday 23 November', '0.0 °C', 'No plant at risk'],
                ['Tuesday 24 November', '6.1 °C', 'No plant at risk'],
            ],
            self::forecastDays($crawler),
            '0 °C is not colder than the threshold of a frost-sensitive plant.',
        );
        self::assertSelectorCount(0, '.forecast-day.is-at-risk');
        self::assertSelectorTextContains('.coming-up .reminder-empty', 'No reminder coming up.');
    }

    public function testTheCalendarRemindersStayWhenOpenMeteoIsUnreachable(): void
    {
        self::mockTime('2026-11-22 09:30');
        UnavailableDataSources::openMeteo(self::getContainer());
        $rose = PlantFactory::new()->validated()->withEnabledReminders(ReminderType::Pruning, ReminderType::Frost)->create(['commonName' => 'Rose', 'careSheet' => CareSheetFactory::new(['pruningMonths' => [12]])]);
        PlantingFactory::new()->of($rose)->create();

        $crawler = $this->client->request('GET', '/notifications');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('.frost-forecast .forecast-unavailable', 'The frost forecast is unavailable: Open-Meteo did not answer. Try again in a few minutes.');
        self::assertSelectorNotExists('.forecast-list');
        self::assertSame(['Pruning'], $crawler->filter('.coming-up .upcoming .tag')->each(static fn (Crawler $tag): string => $tag->text()));
        self::assertSelectorTextContains('.coming-up', 'Frost alerts are left out: no forecast for now.');
    }

    public function testTheNotificationsAreListedThenMarkedRead(): void
    {
        self::mockTime('2026-12-01 09:30');
        $rose = PlantFactory::new()->validated()->create(['commonName' => 'Rose']);
        $gate = PlantingFactory::new()->of($rose)->create();
        NotificationFactory::new()->read(new \DateTimeImmutable('2026-11-01 08:00'))->create([
            'reminder' => ReminderFactory::new(['plant' => $rose, 'type' => ReminderType::Pruning]),
            'message' => 'Time to prune Rose.',
            'createdAt' => new \DateTimeImmutable('2026-11-01 07:00'),
        ]);
        NotificationFactory::createOne([
            'reminder' => ReminderFactory::new(['plant' => $rose, 'type' => ReminderType::Frost]),
            'message' => 'Frost expected on Tuesday 1 December (-3.2 °C): cover Rose.',
            'createdAt' => new \DateTimeImmutable('2026-12-01 07:00'),
        ]);

        $this->client->request('GET', '/garden');
        self::assertSelectorTextSame('.nav .badge', '1');

        $crawler = $this->client->request('GET', '/notifications');

        self::assertResponseIsSuccessful();
        self::assertSame(
            [['Frost expected on Tuesday 1 December (-3.2 °C): cover Rose.', true], ['Time to prune Rose.', false]],
            $crawler->filter('.notification')->each(static fn ($notification): array => [$notification->filter('p')->first()->text(), str_contains((string) $notification->attr('class'), 'is-unread')]),
            'Newest first; the unread one stands out on this visit.',
        );
        self::assertSelectorTextContains('.notification-frost .meta', 'Rose · Tuesday 1 December, 07:00');
        self::assertSelectorExists('.notification-frost .meta a[href="/garden/' . $gate->getId() . '"]', 'My rose, in the garden.');

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $notifications = self::getContainer()->get(NotificationRepository::class)->findLatest($this->gardenOfTheTest()->getOwner(), $this->gardenOfTheTest());
        self::assertSame('2026-12-01 09:30', $notifications[0]->getReadAt()?->format('Y-m-d H:i'), 'Read now.');
        self::assertSame('2026-11-01 08:00', $notifications[1]->getReadAt()?->format('Y-m-d H:i'), 'Read before.');

        $crawler = $this->client->request('GET', '/notifications');
        self::assertCount(0, $crawler->filter('.notification.is-unread'));
        self::assertSelectorExists('.nav .badge[hidden]');
    }

    /**
     * Open-Meteo answers these daily minimums, until the kernel shuts down. To call before the first request.
     *
     * @param array<string, float> $minimums
     */
    private static function forecast(array $minimums): void
    {
        $response = new JsonMockResponse([
            'latitude' => 46.6,
            'longitude' => 2.4,
            'timezone' => 'Europe/Paris',
            'daily' => ['time' => array_keys($minimums), 'temperature_2m_min' => array_values($minimums)],
        ]);

        // Follows the clock of the test, like the "clock" service
        self::getContainer()->set(OpenMeteoClient::class, new OpenMeteoClient(new MockHttpClient($response, 'https://api.open-meteo.com'), new DataSourceCache(new ArrayAdapter(), false), new NullLogger(), new Clock(), 46.6, 2.4));
    }

    /**
     * @return list<array{string, string, string}> the day, its minimum and the plants at risk
     */
    private static function forecastDays(Crawler $crawler): array
    {
        return $crawler->filter('.frost-forecast .forecast-day')->each(static fn (Crawler $day): array => [
            $day->filter('.forecast-date')->text(),
            $day->filter('.forecast-min')->text(),
            $day->filter('.forecast-plants')->text(),
        ]);
    }
}
