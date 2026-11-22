<?php

namespace App\DataSource\OpenMeteo;

use App\DataSource\DataSourceCache;
use App\DataSource\Exception\DataSourceUnavailableException;
use App\DataSource\Exception\UnexpectedResponseException;
use App\DataSource\OpenMeteo\Model\Place;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The places of a name, for the location of a garden: the geocoding of Open-Meteo, under CC BY 4.0, with the data of
 * GeoNames. A place without timezone is no place for a garden: it is left out.
 *
 * @see https://open-meteo.com/en/docs/geocoding-api
 */
final readonly class GeocodingClient
{
    public const int MIN_NAME_LENGTH = 2;
    private const int MAX_NAME_LENGTH = 100;
    private const int MAX_PLACES = 5;
    private const int CACHE_TTL_IN_SECONDS = 30 * 24 * 3600;

    public function __construct(
        #[Target('open_meteo_geocoding.client')]
        private HttpClientInterface $httpClient,
        private DataSourceCache $cache,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param string $language of the names of the places found
     *
     * @return list<Place> the most populated first, none for a name too short
     *
     * @throws DataSourceUnavailableException
     */
    public function search(string $name, string $language): array
    {
        $name = mb_substr(trim($name), 0, self::MAX_NAME_LENGTH);
        if (mb_strlen($name) < self::MIN_NAME_LENGTH) {
            return [];
        }

        return $this->cache->get('open_meteo.geocoding.' . $language . '.' . mb_strtolower($name), self::CACHE_TTL_IN_SECONDS, function () use ($name, $language): array {
            try {
                return self::places($this->httpClient->request('GET', '/v1/search', [
                    'query' => ['name' => $name, 'count' => self::MAX_PLACES, 'language' => $language, 'format' => 'json'],
                ])->toArray());
            } catch (ExceptionInterface|UnexpectedResponseException $e) {
                $this->logger->warning('Open-Meteo geocoding failed.', ['exception' => $e, 'name' => $name]);

                throw DataSourceUnavailableException::failed('Open-Meteo', $e);
            }
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

            $places[] = new Place(
                $result['id'],
                $result['name'],
                \is_string($result['admin1'] ?? null) ? $result['admin1'] : null,
                \is_string($result['country'] ?? null) ? $result['country'] : null,
                (float) $latitude,
                (float) $longitude,
                $timezone,
            );
        }

        return \array_slice($places, 0, self::MAX_PLACES);
    }
}
