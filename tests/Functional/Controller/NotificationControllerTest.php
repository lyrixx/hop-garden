<?php

namespace App\Tests\Functional\Controller;

use App\DataSource\DataSourceCache;
use App\DataSource\OpenMeteo\OpenMeteoClient;
use App\Garden\Entity\Planting;
use App\Plant\Entity\Plant;
use App\Reminder\Entity\Notification;
use App\Reminder\Model\ReminderType;
use App\Reminder\NotificationRepository;
use App\Tests\Utils\Factory\PlantFactory;
use App\Tests\Utils\Factory\PlantingFactory;
use App\Tests\Utils\Http\UnavailableDataSources;
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

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
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
        $rose = PlantFactory::validated('Rose', PlantFactory::careSheet(minTemperatureC: -20, pruningMonths: [2], deadheadingMonths: [7], cuttingMonths: [12]));
        $rose->enableReminder(ReminderType::Pruning);
        $rose->enableReminder(ReminderType::Cutting);
        $rose->enableReminder(ReminderType::Deadheading);
        $rose->enableReminder(ReminderType::Frost);
        $lemonTree = PlantFactory::validated('Lemon tree', PlantFactory::careSheet(minTemperatureC: -3, frostSensitive: true, pruningMonths: [12]));
        $lemonTree->enableReminder(ReminderType::Frost);
        $lemonTree->enableReminder(ReminderType::Pruning);
        $lemonTree->disableReminder(ReminderType::Pruning);
        $roseByTheGate = $this->save($rose, 1)[0];
        $this->save($lemonTree, 2);

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
        $this->save(PlantFactory::validated('Rose', PlantFactory::careSheet(pruningMonths: [12])), 1);

        $this->client->request('GET', '/notifications');

        self::assertResponseIsSuccessful();
        self::assertSelectorCount(0, '.coming-up .upcoming');
        self::assertSelectorTextContains('.coming-up .reminder-empty', 'No reminder coming up. Turn reminders on from the card of a species.');
    }

    public function testTheFrostForecastListsThePlantsAtRiskEachDay(): void
    {
        self::mockTime('2026-11-22 09:30');
        $hydrangea = PlantFactory::validated(careSheet: PlantFactory::careSheet(minTemperatureC: -10));
        $lemonTree = PlantFactory::validated('Lemon tree', PlantFactory::careSheet(minTemperatureC: -3, frostSensitive: true));
        $hydrangeaInTheShade = $this->save($hydrangea, 1)[0];
        $this->save($lemonTree, 0);
        $this->save(PlantFactory::validated('Rose', PlantFactory::careSheet(minTemperatureC: -20)), 1);
        $this->save(PlantFactory::draft('Olive tree', PlantFactory::careSheet(minTemperatureC: -8)), 0);

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
        $lemonTree = PlantFactory::validated('Lemon tree', PlantFactory::careSheet(minTemperatureC: -3, frostSensitive: true));
        $lemonTree->enableReminder(ReminderType::Frost);
        $this->save($lemonTree, 1);

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
        $rose = PlantFactory::validated('Rose', PlantFactory::careSheet(pruningMonths: [12]));
        $rose->enableReminder(ReminderType::Pruning);
        $rose->enableReminder(ReminderType::Frost);
        $this->save($rose, 1);

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
        $rose = PlantFactory::validated('Rose');
        $pruning = $rose->enableReminder(ReminderType::Pruning);
        $frost = $rose->enableReminder(ReminderType::Frost);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($rose);
        $gate = PlantingFactory::of($rose);
        $entityManager->persist($gate);
        $read = new Notification($pruning, 'Time to prune Rose.', new \DateTimeImmutable('2026-11-01 07:00'));
        $read->markRead(new \DateTimeImmutable('2026-11-01 08:00'));
        $entityManager->persist($read);
        $entityManager->persist(new Notification($frost, 'Frost expected on Tuesday 1 December (-3.2 °C): cover Rose.', new \DateTimeImmutable('2026-12-01 07:00')));
        $entityManager->flush();

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
        $notifications = self::getContainer()->get(NotificationRepository::class)->findLatest();
        self::assertSame('2026-12-01 09:30', $notifications[0]->getReadAt()?->format('Y-m-d H:i'), 'Read now.');
        self::assertSame('2026-11-01 08:00', $notifications[1]->getReadAt()?->format('Y-m-d H:i'), 'Read before.');

        $crawler = $this->client->request('GET', '/notifications');
        self::assertCount(0, $crawler->filter('.notification.is-unread'));
        self::assertSelectorExists('.nav .badge[hidden]');
    }

    /**
     * @return list<Planting> the plants of the garden following its care sheet
     */
    private function save(Plant $plant, int $plantings): array
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($plant);
        $saved = [];
        for ($i = 0; $i < $plantings; ++$i) {
            $saved[] = $planting = PlantingFactory::of($plant);
            $entityManager->persist($planting);
        }
        $entityManager->flush();

        return $saved;
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
