<?php

namespace App\Tests\Unit\DataSource\OpenMeteo;

use App\DataSource\DataSourceCache;
use App\DataSource\Exception\DataSourceUnavailableException;
use App\DataSource\OpenMeteo\Model\DailyMinimum;
use App\DataSource\OpenMeteo\OpenMeteoClient;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;

final class OpenMeteoClientTest extends TestCase
{
    private TestHandler $logs;
    private MockClock $clock;

    protected function setUp(): void
    {
        $this->logs = new TestHandler();
        $this->clock = new MockClock('2026-12-01 07:00', 'Europe/Paris');
    }

    public function testTheDailyMinimumsAtTheGarden(): void
    {
        $response = self::forecast(['2026-12-01' => -3.2, '2026-12-02' => null, '2026-12-03' => -12.4]);
        $httpClient = new MockHttpClient($response, 'https://api.open-meteo.com');

        $forecast = $this->client($httpClient)->getFrostForecast(3);

        self::assertSame('GET', $response->getRequestMethod());
        self::assertSame('https://api.open-meteo.com/v1/forecast?latitude=46.6&longitude=2.4&daily=temperature_2m_min&forecast_days=3&timezone=auto', $response->getRequestUrl());
        self::assertSame(46.6, $forecast->latitude);
        self::assertSame(2.4, $forecast->longitude);
        self::assertSame(
            [['2026-12-01 00:00 Europe/Paris', -3.2], ['2026-12-03 00:00 Europe/Paris', -12.4]],
            array_map(static fn (DailyMinimum $day): array => [$day->date->format('Y-m-d H:i e'), $day->minTemperatureC], $forecast->days),
            'A day without model data is left out; the dates are those of the garden.',
        );
    }

    public function testAnIntegerTemperatureIsAFloat(): void
    {
        $forecast = $this->client(new MockHttpClient(self::forecast(['2026-12-01' => -3]), 'https://api.open-meteo.com'))->getFrostForecast(1);

        self::assertSame(-3.0, $forecast->days[0]->minTemperatureC);
    }

    #[TestWith([0, 1])]
    #[TestWith([-4, 1])]
    #[TestWith([16, 16])]
    #[TestWith([40, 16])]
    public function testTheForecastLastsFrom1To16Days(int $days, int $forecastDays): void
    {
        $response = self::forecast(['2026-12-01' => 1.0]);

        $this->client(new MockHttpClient($response, 'https://api.open-meteo.com'))->getFrostForecast($days);

        self::assertStringContainsString('&forecast_days=' . $forecastDays . '&', $response->getRequestUrl());
    }

    public function testAForecastIsValidForItsDay(): void
    {
        $httpClient = new MockHttpClient([
            self::forecast(['2026-12-01' => -3.2]),
            self::forecast(['2026-12-01' => -3.2, '2026-12-02' => -8.5]),
            self::forecast(['2026-12-02' => -8.5]),
        ], 'https://api.open-meteo.com');
        $client = $this->client($httpClient);

        $client->getFrostForecast(1);
        $this->clock->modify('+10 hours');
        $client->getFrostForecast(1);
        self::assertSame(1, $httpClient->getRequestsCount(), 'Cached for the day.');

        $client->getFrostForecast(2);
        self::assertSame(2, $httpClient->getRequestsCount(), 'Another length, another forecast.');

        $this->clock->modify('+1 day');
        self::assertSame('2026-12-02', $client->getFrostForecast(1)->days[0]->date->format('Y-m-d'));
        self::assertSame(3, $httpClient->getRequestsCount(), 'A new day, a new forecast.');
    }

    #[DataProvider('provideAFailureIsUnavailableCases')]
    public function testAFailureIsUnavailable(MockResponse $response): void
    {
        try {
            $this->client(new MockHttpClient($response, 'https://api.open-meteo.com'))->getFrostForecast(3);
            self::fail('The forecast must fail.');
        } catch (DataSourceUnavailableException $e) {
            self::assertStringStartsWith('Open-Meteo ', $e->getMessage());
        }

        self::assertTrue($this->logs->hasRecordThatContains('Open-Meteo forecast failed.', Level::Warning));
    }

    /**
     * @return iterable<string, array{MockResponse}>
     */
    public static function provideAFailureIsUnavailableCases(): iterable
    {
        yield 'an error status' => [new JsonMockResponse(['error' => true, 'reason' => 'Parameter forecast_days must be between 0 and 16'], ['http_code' => 400])];
        yield 'a server error' => [new MockResponse('Bad gateway', ['http_code' => 502])];
        yield 'not JSON' => [new MockResponse('<html>Maintenance</html>', ['response_headers' => ['content-type' => 'text/html']])];
        yield 'a timeout' => [new MockResponse([''])];
        yield 'no daily data' => [new JsonMockResponse(['latitude' => 46.6, 'longitude' => 2.4])];
        yield 'temperatures that are not a list' => [new JsonMockResponse(['latitude' => 46.6, 'longitude' => 2.4, 'daily' => ['time' => ['2026-12-01'], 'temperature_2m_min' => 'none']])];
        yield 'an unknown timezone' => [new JsonMockResponse(['latitude' => 46.6, 'longitude' => 2.4, 'timezone' => 'Mars/Olympus', 'daily' => ['time' => ['2026-12-01'], 'temperature_2m_min' => [-3.2]]])];
        yield 'an invalid date' => [new JsonMockResponse(['latitude' => 46.6, 'longitude' => 2.4, 'daily' => ['time' => ['tomorrow-ish'], 'temperature_2m_min' => [-3.2]]])];
        yield 'a temperature that is not a number' => [new JsonMockResponse(['latitude' => 46.6, 'longitude' => 2.4, 'daily' => ['time' => ['2026-12-01'], 'temperature_2m_min' => ['cold']]])];
    }

    private function client(MockHttpClient $httpClient): OpenMeteoClient
    {
        return new OpenMeteoClient($httpClient, new DataSourceCache(new ArrayAdapter(), false), new Logger('test', [$this->logs]), $this->clock, 46.6, 2.4);
    }

    /**
     * @param non-empty-array<string, float|int|null> $minimums
     */
    private static function forecast(array $minimums): JsonMockResponse
    {
        return new JsonMockResponse([
            'latitude' => 46.6,
            'longitude' => 2.4,
            'timezone' => 'Europe/Paris',
            'daily' => ['time' => array_keys($minimums), 'temperature_2m_min' => array_values($minimums)],
        ]);
    }
}
