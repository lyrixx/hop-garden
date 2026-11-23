<?php

namespace App\DataSource\OpenMeteo;

use App\DataSource\DataSourceCache;
use App\DataSource\Exception\DataSourceUnavailableException;
use App\DataSource\Exception\UnexpectedResponseException;
use App\DataSource\OpenMeteo\Model\Place;
use App\DataSource\OpenMeteo\Model\PlaceQuery;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The places of a name or of a postcode, for the location of a garden: the geocoding of Open-Meteo, under CC BY 4.0,
 * with the data of GeoNames. A place without timezone is no place for a garden: it is left out.
 *
 * @see https://open-meteo.com/en/docs/geocoding-api
 */
final readonly class GeocodingClient
{
    public const int MIN_NAME_LENGTH = 2;
    private const int MAX_NAME_LENGTH = 100;
    private const int MAX_PLACES = 5;
    // The most Open-Meteo returns: all the places of a postcode, to find the one of a name among them
    private const int MAX_PLACES_OF_A_POSTCODE = 100;
    private const int CACHE_TTL_IN_SECONDS = 30 * 24 * 3600;

    public function __construct(
        #[Target('open_meteo_geocoding.client')]
        private HttpClientInterface $httpClient,
        private DataSourceCache $cache,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param string $query    a name, "Paron, France", a postcode, or a postcode and a name in any order
     * @param string $language of the names of the places found
     *
     * @return list<Place> the most populated first, none for a query too short
     *
     * @throws DataSourceUnavailableException
     */
    public function search(string $query, string $language): array
    {
        $query = mb_substr(trim($query), 0, self::MAX_NAME_LENGTH);
        if (mb_strlen($query) < self::MIN_NAME_LENGTH) {
            return [];
        }

        // As typed first: Open-Meteo knows "Paris 17" and "Paron, France"
        $places = $this->request($query, self::MAX_PLACES, $language);
        $parsed = PlaceQuery::parse($query);
        if ([] !== $places || null === $parsed->postcode) {
            return $places;
        }

        return $this->searchByPostcode($parsed->postcode, $parsed->name, $language);
    }

    /**
     * Open-Meteo matches the beginning of one name or of one postcode, never both: "89100 Paron" finds nothing. So the
     * places of the postcode, then the ones of the name among them. None of that name ("89100 Parron", misspelled): the
     * places of the postcode, then the ones of the name, for a place that Open-Meteo knows without this postcode
     * (London has none).
     *
     * @return list<Place>
     */
    private function searchByPostcode(string $postcode, ?string $name, string $language): array
    {
        $ofPostcode = [];
        foreach ($this->request($postcode, self::MAX_PLACES_OF_A_POSTCODE, $language) as $place) {
            if ($place->hasPostcode($postcode)) {
                $ofPostcode[$place->id] = $place->foundByPostcode($postcode);
            }
        }

        if (null === $name) {
            return \array_slice(array_values($ofPostcode), 0, self::MAX_PLACES);
        }

        // "Paron" of "Paron, France": the country or the region after a comma is no part of its name
        $placeName = explode(',', $name, 2)[0];
        $named = array_filter($ofPostcode, static fn (Place $place): bool => $place->isNamed($placeName));
        if ([] !== $named) {
            return \array_slice(array_values($named), 0, self::MAX_PLACES);
        }

        $places = $ofPostcode;
        if (\count($places) < self::MAX_PLACES) {
            foreach ($this->request($name, self::MAX_PLACES, $language) as $place) {
                // Found by its postcode already
                $places[$place->id] ??= $place;
            }
        }

        return \array_slice(array_values($places), 0, self::MAX_PLACES);
    }

    /**
     * @return list<Place> the most populated first
     *
     * @throws DataSourceUnavailableException
     */
    private function request(string $name, int $count, string $language): array
    {
        return $this->cache->get('open_meteo.geocoding.' . $language . '.' . $count . '.' . mb_strtolower($name), self::CACHE_TTL_IN_SECONDS, function () use ($name, $count, $language): array {
            try {
                $places = self::places($this->httpClient->request('GET', '/v1/search', [
                    'query' => ['name' => $name, 'count' => $count, 'language' => $language, 'format' => 'json'],
                ])->toArray());
            } catch (ExceptionInterface|UnexpectedResponseException $e) {
                $this->logger->warning('Open-Meteo geocoding failed.', ['exception' => $e, 'name' => $name]);

                throw DataSourceUnavailableException::failed('Open-Meteo', $e);
            }

            // The recorded responses of the demo mode answer the same, whatever the count
            return \array_slice($places, 0, $count);
        });
    }

    /**
     * @param array<mixed> $data
     *
     * @return list<Place>
     */
    private static function places(array $data): array
    {
        // No "results" at all for a name it does not know
        $results = $data['results'] ?? [];
        if (!\is_array($results) || !array_is_list($results)) {
            throw new UnexpectedResponseException('The places found by Open-Meteo are not a list.');
        }

        $places = [];
        foreach ($results as $result) {
            $latitude = \is_array($result) ? $result['latitude'] ?? null : null;
            $longitude = \is_array($result) ? $result['longitude'] ?? null : null;
            if (!\is_array($result) || !\is_int($result['id'] ?? null) || !\is_string($result['name'] ?? null) || !\is_int($latitude) && !\is_float($latitude) || !\is_int($longitude) && !\is_float($longitude)) {
                throw new UnexpectedResponseException('A place found by Open-Meteo has no id, no name or no position.');
            }

            $timezone = $result['timezone'] ?? null;
            if (!\is_string($timezone) || '' === $timezone) {
                continue;
            }

            $postcodes = $result['postcodes'] ?? [];

            $places[] = new Place(
                $result['id'],
                $result['name'],
                \is_string($result['admin1'] ?? null) ? $result['admin1'] : null,
                \is_string($result['country'] ?? null) ? $result['country'] : null,
                (float) $latitude,
                (float) $longitude,
                $timezone,
                \is_array($postcodes) ? array_values(array_filter($postcodes, \is_string(...))) : [],
            );
        }

        return $places;
    }
}
