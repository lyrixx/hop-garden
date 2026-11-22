<?php

namespace App\DataSource\OpenMeteo;

use App\DataSource\DataSourceCache;
use App\DataSource\Exception\DataSourceUnavailableException;
use App\DataSource\Exception\UnexpectedResponseException;
use App\DataSource\OpenMeteo\Model\DailyMinimum;
use App\DataSource\OpenMeteo\Model\FrostForecast;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Weather data by Open-Meteo.com, under CC BY 4.0.
 *
 * @see https://open-meteo.com/en/docs
 */
final readonly class OpenMeteoClient
{
    public const int MAX_FORECAST_DAYS = 16;
    private const int CACHE_TTL_IN_SECONDS = 3600;

    public function __construct(
        #[Target('open_meteo.client')]
        private HttpClientInterface $httpClient,
        private DataSourceCache $cache,
        private LoggerInterface $logger,
        private ClockInterface $clock,
        #[Autowire(env: 'float:GARDEN_LATITUDE')]
        private float $latitude,
        #[Autowire(env: 'float:GARDEN_LONGITUDE')]
        private float $longitude,
    ) {
    }

    public function getFrostForecast(int $days): FrostForecast
    {
        $days = max(1, min(self::MAX_FORECAST_DAYS, $days));

        // A forecast is only valid on the day it was made
        $key = \sprintf('open_meteo.forecast.%F.%F.%d.%s', $this->latitude, $this->longitude, $days, $this->clock->now()->format('Y-m-d'));

        return $this->cache->get($key, self::CACHE_TTL_IN_SECONDS, function () use ($days): FrostForecast {
            try {
                return self::frostForecast($this->httpClient->request('GET', '/v1/forecast', [
                    'query' => [
                        'latitude' => $this->latitude,
                        'longitude' => $this->longitude,
                        'daily' => 'temperature_2m_min',
                        'forecast_days' => $days,
                        'timezone' => 'auto',
                    ],
                ])->toArray());
            } catch (ExceptionInterface|UnexpectedResponseException $e) {
                $this->logger->warning('Open-Meteo forecast failed.', ['exception' => $e]);

                throw DataSourceUnavailableException::failed('Open-Meteo', $e);
            }
        });
    }

    /**
     * @param array<mixed> $data
     */
    private static function frostForecast(array $data): FrostForecast
    {
        $latitude = $data['latitude'] ?? null;
        $longitude = $data['longitude'] ?? null;
        $daily = $data['daily'] ?? null;
        $dates = \is_array($daily) ? $daily['time'] ?? null : null;
        $temperatures = \is_array($daily) ? $daily['temperature_2m_min'] ?? null : null;
        if (!\is_int($latitude) && !\is_float($latitude) || !\is_int($longitude) && !\is_float($longitude) || !\is_array($dates) || !\is_array($temperatures)) {
            throw new UnexpectedResponseException('Open-Meteo sent no location or no daily minimum temperatures.');
        }

        $timezone = self::timezone($data['timezone'] ?? 'UTC');
        $minimums = [];
        foreach ($dates as $i => $date) {
            $minimum = $temperatures[$i] ?? null;
            // Open-Meteo sends null for a day it has no model data for
            if (null === $minimum) {
                continue;
            }

            $day = \is_string($date) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $date, $timezone) : false;
            if (false === $day || !\is_int($minimum) && !\is_float($minimum)) {
                throw new UnexpectedResponseException(\sprintf('Open-Meteo sent an invalid daily minimum temperature for day %d.', $i));
            }
            $minimums[] = new DailyMinimum($day, (float) $minimum);
        }

        return new FrostForecast((float) $latitude, (float) $longitude, $minimums);
    }

    private static function timezone(mixed $name): \DateTimeZone
    {
        try {
            return new \DateTimeZone(\is_string($name) ? $name : '');
        } catch (\DateInvalidTimeZoneException $e) {
            throw new UnexpectedResponseException('Open-Meteo sent an unknown timezone.', previous: $e);
        }
    }
}
