<?php

namespace App\Tests\Http;

use App\DataSource\DataSourceCache;
use App\DataSource\OpenMeteo\OpenMeteoClient;
use App\DataSource\Trefle\TrefleClient;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\Clock;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Trefle or Open-Meteo answer "503 Service unavailable", until the kernel shuts down. To call before the first
 * request of a KernelBrowser, which reboots the kernel between requests.
 */
final class UnavailableDataSources
{
    public static function trefle(ContainerInterface $container): void
    {
        $container->set(TrefleClient::class, new TrefleClient(self::httpClient('https://trefle.io'), new DataSourceCache(new ArrayAdapter(), false), new NullLogger(), 'test-token', false));
    }

    public static function openMeteo(ContainerInterface $container): void
    {
        // Follows the clock of the test, like the "clock" service
        $container->set(OpenMeteoClient::class, new OpenMeteoClient(self::httpClient('https://api.open-meteo.com'), new DataSourceCache(new ArrayAdapter(), false), new NullLogger(), new Clock(), 46.6, 2.4));
    }

    private static function httpClient(string $baseUri): MockHttpClient
    {
        return new MockHttpClient(static fn (): MockResponse => new MockResponse('Service unavailable', ['http_code' => 503]), $baseUri);
    }
}
