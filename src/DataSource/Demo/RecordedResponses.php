<?php

namespace App\DataSource\Demo;

use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Serves the responses stored in fixtures/http/ in place of Trefle and Open-Meteo (its forecast and its geocoding).
 */
final readonly class RecordedResponses
{
    public function __construct(
        #[Autowire('%kernel.project_dir%/fixtures/http')]
        private string $fixturesDir,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param array<string, mixed> $options
     */
    public function __invoke(string $method, string $url, array $options): MockResponse
    {
        $parts = parse_url($url);
        $host = $parts['host'] ?? '';
        $path = $parts['path'] ?? '/';
        parse_str($parts['query'] ?? '', $query);

        return match (true) {
            'trefle.io' === $host && '/api/v1/plants/search' === $path => $this->trefleSearch($this->stringParameter($query, 'q')),
            'api.open-meteo.com' === $host && '/v1/forecast' === $path => $this->openMeteoForecast((int) $this->stringParameter($query, 'forecast_days'), (float) $this->stringParameter($query, 'latitude'), (float) $this->stringParameter($query, 'longitude')),
            'geocoding-api.open-meteo.com' === $host && '/v1/search' === $path => $this->openMeteoGeocoding($this->stringParameter($query, 'name')),
            default => throw new \LogicException(\sprintf('No recorded response for "%s %s".', $method, $url)),
        };
    }

    /**
     * The searches of places a recording answers, for the demo mode to suggest: the names of the files of
     * open-meteo/geocoding/, capitalized.
     *
     * @return list<string> the names in alphabetical order ("Lyon", "Paron"...), then the postcodes ("89100"...)
     */
    public function placeQueries(): array
    {
        $queries = [];
        foreach (glob($this->fixturesDir . '/open-meteo/geocoding/*.json') ?: [] as $file) {
            $name = basename($file, '.json');
            if (!str_starts_with($name, '_')) {
                $queries[] = ucfirst($name);
            }
        }
        usort($queries, static fn (string $a, string $b): int => [ctype_digit($a), $a] <=> [ctype_digit($b), $b]);

        return $queries;
    }

    private function trefleSearch(string $query): MockResponse
    {
        /** @var array<string, string> $aliases */
        $aliases = json_decode((string) file_get_contents($this->fixturesDir . '/trefle/aliases.json'), true, flags: JSON_THROW_ON_ERROR);
        $name = $aliases[$this->slug($query)] ?? null;

        return $this->json(null !== $name ? "trefle/search/{$name}.json" : 'trefle/search/_empty.json');
    }

    /**
     * The recorded forecast is moved to start today, so the demo always warns about the coming days, and to the position
     * asked: the same cold snap in every garden.
     */
    private function openMeteoForecast(int $days, float $latitude, float $longitude): MockResponse
    {
        /** @var array{latitude: float, longitude: float, daily: array{time: list<string>, temperature_2m_min: list<float>}} $forecast */
        $forecast = json_decode((string) file_get_contents($this->fixturesDir . '/open-meteo/forecast-frost.json'), true, flags: JSON_THROW_ON_ERROR);
        $forecast['latitude'] = $latitude;
        $forecast['longitude'] = $longitude;

        $minimums = \array_slice($forecast['daily']['temperature_2m_min'], 0, max(1, $days));
        $today = $this->clock->now()->setTime(0, 0);

        $forecast['daily']['temperature_2m_min'] = $minimums;
        $forecast['daily']['time'] = array_map(
            static fn (int $offset): string => $today->modify("+{$offset} days")->format('Y-m-d'),
            array_keys($minimums),
        );

        return new MockResponse(json_encode($forecast, JSON_THROW_ON_ERROR), ['response_headers' => ['content-type' => 'application/json']]);
    }

    /**
     * The places of a few cities and postcodes, whatever the language asked and the count: any other name finds none.
     */
    private function openMeteoGeocoding(string $name): MockResponse
    {
        $file = 'open-meteo/geocoding/' . $this->slug($name) . '.json';

        return $this->json(is_file($this->fixturesDir . '/' . $file) ? $file : 'open-meteo/geocoding/_empty.json');
    }

    private function json(string $file): MockResponse
    {
        return new MockResponse((string) file_get_contents($this->fixturesDir . '/' . $file), ['response_headers' => ['content-type' => 'application/json']]);
    }

    private function slug(string $value): string
    {
        return (new AsciiSlugger())->slug($value)->lower()->toString();
    }

    /**
     * @param array<mixed> $query
     */
    private function stringParameter(array $query, string $name): string
    {
        $value = $query[$name] ?? '';

        return \is_string($value) ? $value : '';
    }
}
