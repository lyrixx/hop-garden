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
    private const array PARON = ['id' => 2988475, 'name' => 'Paron', 'latitude' => 48.17888, 'longitude' => 3.25075, 'timezone' => 'Europe/Paris', 'postcodes' => ['89100'], 'country' => 'France', 'admin1' => 'Bourgogne-Franche-Comté'];
    private const array PARON_ARKANSAS = ['id' => 4125531, 'name' => 'Paron', 'latitude' => 34.77287, 'longitude' => -92.7585, 'timezone' => 'America/Chicago', 'postcodes' => ['72122'], 'country' => 'United States', 'admin1' => 'Arkansas'];
    private const array SAINT_CLEMENT = ['id' => 2981060, 'name' => 'Saint-Clément', 'latitude' => 48.21724, 'longitude' => 3.29609, 'timezone' => 'Europe/Paris', 'postcodes' => ['89100'], 'country' => 'France', 'admin1' => 'Bourgogne-Franche-Comté'];
    private const array SENS = ['id' => 2975050, 'name' => 'Sens', 'latitude' => 48.19738, 'longitude' => 3.28328, 'timezone' => 'Europe/Paris', 'postcodes' => ['89100', '89090 CEDEX'], 'country' => 'France', 'admin1' => 'Bourgogne-Franche-Comté'];

    public function testThePlacesOfAName(): void
    {
        $response = new JsonMockResponse(['results' => [
            ['id' => 2996944, 'name' => 'Lyon', 'latitude' => 45.74846, 'longitude' => 4.84671, 'timezone' => 'Europe/Paris', 'country' => 'France', 'admin1' => 'Auvergne-Rhône-Alpes', 'postcodes' => ['69001', 69002]],
            ['id' => 1, 'name' => 'Lyon', 'latitude' => 0, 'longitude' => 0, 'country' => 'Nowhere'],
            ['id' => 6693679, 'name' => 'Singapore', 'latitude' => 1.28967, 'longitude' => 103.85007, 'timezone' => 'Asia/Singapore', 'country' => 'Singapore', 'admin1' => 'Singapore', 'postcodes' => '018989'],
        ]]);

        $places = self::client($response)->search('  Lyon ', 'fr');

        self::assertSame('https://geocoding-api.open-meteo.com/v1/search?name=Lyon&count=5&language=fr&format=json', $response->getRequestUrl());
        self::assertSame(['Lyon, Auvergne-Rhône-Alpes, France', 'Singapore'], array_map(static fn (Place $place): string => $place->label(), $places), 'A place without timezone is left out; a region named as its city once.');
        self::assertSame([45.74846, 4.84671, 'Europe/Paris'], [$places[0]->latitude, $places[0]->longitude, $places[0]->timezone]);
        self::assertSame([['69001'], []], [$places[0]->postcodes, $places[1]->postcodes], 'Its postcodes that are strings, in a list.');
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

    public function testAPostcodeAndANameInAnyOrder(): void
    {
        foreach (['89100 Paron', 'Paron 89100', 'Paron (89100)', '89100 Paron, France'] as $query) {
            $requests = [];
            $places = self::client(self::answering(['89100' => [self::SENS, self::PARON, self::SAINT_CLEMENT]], $requests))->search($query, 'fr');

            self::assertSame(['Paron (89100), Bourgogne-Franche-Comté, France'], array_map(static fn (Place $place): string => $place->suggestion(), $places), $query);
            self::assertSame([[$query, '5'], ['89100', '100']], $requests, 'As typed first, which Open-Meteo does not find; then the places of the postcode, for the name among them.');
        }
    }

    public function testAPostcodeAloneAsTyped(): void
    {
        $requests = [];
        $places = self::client(self::answering(['89100' => [self::SENS, self::PARON, self::SAINT_CLEMENT, self::PARON_ARKANSAS]], $requests))->search('89100', 'fr');

        self::assertSame(['Sens', 'Paron', 'Saint-Clément', 'Paron'], array_map(static fn (Place $place): string => $place->name, $places));
        self::assertSame([['89100', '5']], $requests);
    }

    public function testANameNotAmongThePlacesOfItsPostcode(): void
    {
        // Misspelled: Open-Meteo finds other places of that name. Paron is one of them for the test, found once.
        $requests = [];
        $places = self::client(self::answering(['89100' => [self::SENS, self::PARON, self::PARON_ARKANSAS], 'Parron' => [self::PARON, self::PARON_ARKANSAS]], $requests))->search('Parron 89100', 'fr');

        self::assertSame(
            ['Sens (89100), Bourgogne-Franche-Comté, France', 'Paron (89100), Bourgogne-Franche-Comté, France', 'Paron (72122), Arkansas, United States'],
            array_map(static fn (Place $place): string => $place->suggestion(), $places),
            'The places of the postcode first (of the postcode only, whatever Open-Meteo returns), then the ones of the name.',
        );
        self::assertSame([['Parron 89100', '5'], ['89100', '100'], ['Parron', '5']], $requests);

        $requests = [];
        self::client(self::answering(['89100' => [self::SENS, self::PARON, self::SAINT_CLEMENT, ['id' => 1] + self::SENS, ['id' => 2] + self::SENS]], $requests))->search('Parron 89100', 'fr');
        self::assertSame([['Parron 89100', '5'], ['89100', '100']], $requests, 'Not the name: the places of the postcode fill the suggestions.');
    }

    public function testAPostcodeItDoesNotKnowGetsThePlacesOfTheName(): void
    {
        $requests = [];
        $places = self::client(self::answering(['Paron' => [self::PARON, self::PARON_ARKANSAS]], $requests))->search('Paron 99999', 'fr');

        self::assertSame(['Paron (89100), Bourgogne-Franche-Comté, France', 'Paron (72122), Arkansas, United States'], array_map(static fn (Place $place): string => $place->suggestion(), $places), 'With their own postcode.');
        self::assertSame([['Paron 99999', '5'], ['99999', '100'], ['Paron', '5']], $requests);
    }

    /**
     * Open-Meteo, answering the places of a few names, and none for any other.
     *
     * @param array<string, list<array<string, mixed>>> $places   by name
     * @param list<array{string, string}>               $requests the name and the count of each request, as made
     */
    private static function answering(array $places, array &$requests): \Closure
    {
        return static function (string $method, string $url) use ($places, &$requests): MockResponse {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            self::assertIsString($query['name'] ?? null);
            self::assertIsString($query['count'] ?? null);
            $requests[] = [$query['name'], $query['count']];

            return new JsonMockResponse(isset($places[$query['name']]) ? ['results' => $places[$query['name']]] : ['generationtime_ms' => 0.1]);
        };
    }

    private static function client(MockResponse|\Closure $response): GeocodingClient
    {
        return new GeocodingClient(new MockHttpClient($response, 'https://geocoding-api.open-meteo.com'), new DataSourceCache(new ArrayAdapter(), false), new NullLogger());
    }
}
