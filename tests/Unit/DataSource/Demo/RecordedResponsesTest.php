<?php

namespace App\Tests\Unit\DataSource\Demo;

use App\DataSource\Demo\DemoHttpClientFactory;
use App\DataSource\Demo\RecordedResponses;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class RecordedResponsesTest extends TestCase
{
    private MockClock $clock;

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-12-03 15:30');
    }

    #[TestWith(['hortensia', 'Hydrangea macrophylla'])]
    #[TestWith(['Hortensias', 'Hydrangea macrophylla'])]
    #[TestWith(['Panicled hydrangea', 'Hydrangea paniculata'])]
    #[TestWith(['ROSES', 'Rosa canina'])]
    public function testANameIsFoundThroughItsAlias(string $query, string $firstScientificName): void
    {
        $data = $this->get('https://trefle.io/api/v1/plants/search?q=' . rawurlencode($query) . '&token=');

        self::assertSame($firstScientificName, $data['data'][0]['scientific_name']);
    }

    public function testAnUnknownNameFindsNothing(): void
    {
        self::assertSame([], $this->get('https://trefle.io/api/v1/plants/search?q=triffid')['data']);
        self::assertSame([], $this->get('https://trefle.io/api/v1/plants/search')['data'], 'No query at all.');
    }

    public function testTheRecordedForecastStartsToday(): void
    {
        $data = $this->get('https://api.open-meteo.com/v1/forecast?latitude=46.6&longitude=2.4&daily=temperature_2m_min&forecast_days=2&timezone=auto');

        self::assertSame(['2026-12-03', '2026-12-04'], $data['daily']['time']);
        self::assertSame([-3.2, -8.5], $data['daily']['temperature_2m_min']);
        self::assertSame('Europe/Paris', $data['timezone']);
        self::assertSame([46.6, 2.4], [$data['latitude'], $data['longitude']], 'At the position asked.');
    }

    public function testTheRecordedPlacesOfACity(): void
    {
        $paris = $this->get('https://geocoding-api.open-meteo.com/v1/search?name=Paris&count=5&language=fr&format=json');
        self::assertSame(['Europe/Paris', 'America/Chicago', 'America/Chicago'], array_column($paris['results'], 'timezone'), 'Its homonyms too.');

        $paron = $this->get('https://geocoding-api.open-meteo.com/v1/search?name=Paron&count=5&language=fr&format=json');
        self::assertSame([['Paron', 'France', ['89100']], ['Paron', 'United States', ['72122']]], array_map(static fn (array $place): array => [$place['name'], $place['country'], $place['postcodes']], \array_slice($paron['results'], 0, 2)));
        $postcode = $this->get('https://geocoding-api.open-meteo.com/v1/search?name=89100&count=100&language=fr&format=json');
        self::assertContains('Paron', array_column($postcode['results'], 'name'), 'The places of a postcode.');

        self::assertArrayNotHasKey('results', $this->get('https://geocoding-api.open-meteo.com/v1/search?name=Atlantis&count=5&language=en&format=json'), 'As Open-Meteo: no results at all.');
        self::assertArrayNotHasKey('results', $this->get('https://geocoding-api.open-meteo.com/v1/search?name=..%2F..%2Ftrefle%2Faliases&count=5&language=en&format=json'), 'A name, never a path.');
    }

    public function testThePlaceQueriesARecordingAnswers(): void
    {
        $queries = $this->recordedResponses()->placeQueries();

        self::assertSame(['Auxerre', 'Bordeaux', 'Lille', 'London', 'Lyon', 'Nantes', 'Paris', 'Paron', '89000', '89100'], $queries);
        foreach ($queries as $query) {
            self::assertNotEmpty($this->get('https://geocoding-api.open-meteo.com/v1/search?name=' . rawurlencode($query) . '&count=5&language=fr&format=json')['results'] ?? [], $query . ' finds its places.');
        }
    }

    #[TestWith(['forecast_days=0', 1])]
    #[TestWith(['forecast_days=16', 3])]
    #[TestWith(['daily=temperature_2m_min', 1])]
    public function testTheRecordedForecastLastsAtLeastOneDayAndAtMostItsRecording(string $query, int $days): void
    {
        self::assertCount($days, $this->get('https://api.open-meteo.com/v1/forecast?' . $query)['daily']['time']);
    }

    #[TestWith(['GET', 'https://example.com/'])]
    #[TestWith(['GET', 'https://trefle.io/api/v1/species/191052'])]
    #[TestWith(['POST', 'https://api.open-meteo.com/v1/archive'])]
    public function testNothingElseIsRecorded(string $method, string $url): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageIs(\sprintf('No recorded response for "%s %s".', $method, $url));

        $this->client()->request($method, $url);
    }

    public function testOutOfTheDemoModeTheClientIsKept(): void
    {
        $client = new MockHttpClient();

        self::assertSame($client, (new DemoHttpClientFactory($this->recordedResponses(), false))->create($client, 'https://trefle.io'));
    }

    public function testInTheDemoModeRelativeUrlsAnswerFromTheRecordings(): void
    {
        $client = (new DemoHttpClientFactory($this->recordedResponses(), true))->create(new MockHttpClient(), 'https://api.open-meteo.com');

        $response = $client->request('GET', '/v1/forecast', ['query' => ['forecast_days' => 3]]);

        self::assertSame('application/json', $response->getHeaders()['content-type'][0]);
        self::assertSame(['2026-12-03', '2026-12-04', '2026-12-05'], $response->toArray()['daily']['time']);
    }

    /**
     * @return array<string, mixed>
     */
    private function get(string $url): array
    {
        return $this->client()->request('GET', $url)->toArray();
    }

    private function client(): HttpClientInterface
    {
        return new MockHttpClient($this->recordedResponses());
    }

    private function recordedResponses(): RecordedResponses
    {
        return new RecordedResponses(\dirname(__DIR__, 4) . '/fixtures/http', $this->clock);
    }
}
