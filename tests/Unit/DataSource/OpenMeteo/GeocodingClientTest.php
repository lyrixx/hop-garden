<?php

namespace App\Tests\Unit\DataSource\OpenMeteo;

use App\DataSource\DataSourceCache;
use App\DataSource\Exception\DataSourceUnavailableException;
use App\DataSource\OpenMeteo\GeocodingClient;
use App\DataSource\OpenMeteo\Model\Place;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;

final class GeocodingClientTest extends TestCase
{
    public function testThePlacesOfAName(): void
    {
        $response = new JsonMockResponse(['results' => [
            ['id' => 2996944, 'name' => 'Lyon', 'latitude' => 45.74846, 'longitude' => 4.84671, 'timezone' => 'Europe/Paris', 'country' => 'France', 'admin1' => 'Auvergne-Rhône-Alpes'],
            ['id' => 1, 'name' => 'Lyon', 'latitude' => 0, 'longitude' => 0, 'country' => 'Nowhere'],
            ['id' => 6693679, 'name' => 'Singapore', 'latitude' => 1.28967, 'longitude' => 103.85007, 'timezone' => 'Asia/Singapore', 'country' => 'Singapore', 'admin1' => 'Singapore'],
        ]]);

        $places = self::client($response)->search('  Lyon ', 'fr');

        self::assertSame('https://geocoding-api.open-meteo.com/v1/search?name=Lyon&count=5&language=fr&format=json', $response->getRequestUrl());
        self::assertSame(['Lyon, Auvergne-Rhône-Alpes, France', 'Singapore'], array_map(static fn (Place $place): string => $place->label(), $places), 'A place without timezone is left out; a region named as its city once.');
        self::assertSame([45.74846, 4.84671, 'Europe/Paris'], [$places[0]->latitude, $places[0]->longitude, $places[0]->timezone]);
    }

    public function testNoResultsAtAllForANameItDoesNotKnow(): void
    {
        self::assertSame([], self::client(new JsonMockResponse(['generationtime_ms' => 0.3]))->search('Atlantis', 'en'));
        self::assertSame([], self::client(new JsonMockResponse([]))->search('L', 'en'), 'Too short: not asked.');
    }

    public function testAFailureIsUnavailable(): void
    {
        $this->expectException(DataSourceUnavailableException::class);

        self::client(new MockResponse('Bad gateway', ['http_code' => 502]))->search('Lyon', 'en');
    }

    private static function client(MockResponse $response): GeocodingClient
    {
        return new GeocodingClient(new MockHttpClient($response, 'https://geocoding-api.open-meteo.com'), new DataSourceCache(new ArrayAdapter(), false), new NullLogger());
    }
}
