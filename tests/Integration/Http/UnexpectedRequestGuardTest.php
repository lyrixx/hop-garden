<?php

namespace App\Tests\Integration\Http;

use App\DataSource\OpenMeteo\OpenMeteoClient;
use App\DataSource\Trefle\TrefleClient;
use App\Tests\Env\EnvVarsTrait;
use App\Tests\Http\UnexpectedRequestException;
use App\Tests\Http\UnexpectedRequestGuard;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Every HTTP client of the application ends on the guard: none of them reaches the network in the tests. The PSR-18
 * client of the MCP client and of Mercure wraps the default one; the tests replace both by in-process doubles.
 */
final class UnexpectedRequestGuardTest extends KernelTestCase
{
    use EnvVarsTrait;

    public function testTheDefaultClientIsBlocked(): void
    {
        self::bootKernel();
        $httpClient = self::getContainer()->get('http_client');
        self::assertInstanceOf(HttpClientInterface::class, $httpClient);

        self::assertBlocked('GET https://example.com/', static fn () => $httpClient->request('GET', 'https://example.com/'));
    }

    public function testTheTrefleAndOpenMeteoClientsAreBlockedOutOfTheDemoMode(): void
    {
        $this->setEnvVar('APP_DEMO', '0');
        $this->setEnvVar('TREFLE_TOKEN', 'test-token');
        self::bootKernel();

        self::assertBlocked(
            'GET https://trefle.io/api/v1/plants/search?q=hortensia&token=test-token',
            static fn () => self::getContainer()->get(TrefleClient::class)->search('hortensia'),
        );
        self::assertBlocked(
            'GET https://api.open-meteo.com/v1/forecast?latitude=46.6&longitude=2.4&daily=temperature_2m_min&forecast_days=3&timezone=auto',
            static fn () => self::getContainer()->get(OpenMeteoClient::class)->getFrostForecast(3),
        );
    }

    public function testTheAiPlatformsAreBlocked(): void
    {
        $this->setEnvVar('ANTHROPIC_API_KEY', 'test-key');
        $this->setEnvVar('OPENAI_API_KEY', 'sk-test-key');
        self::bootKernel();

        self::assertBlocked('POST https://api.anthropic.com/v1/messages', static fn () => self::platform('ai.platform.anthropic')->invoke('claude-sonnet-5-5', new MessageBag(Message::ofUser('hortensia')))->asText());
        self::assertBlocked('POST https://api.openai.com/v1/responses', static fn () => self::platform('ai.platform.openai')->invoke('gpt-4o-mini', new MessageBag(Message::ofUser('hortensia')))->asText());
    }

    private static function platform(string $id): PlatformInterface
    {
        $platform = self::getContainer()->get($id);
        self::assertInstanceOf(PlatformInterface::class, $platform);

        return $platform;
    }

    /**
     * @param \Closure(): mixed $send
     */
    private static function assertBlocked(string $request, \Closure $send): void
    {
        try {
            $send();
            self::fail(\sprintf('"%s" should have been blocked.', $request));
        } catch (UnexpectedRequestException $e) {
            self::assertSame(\sprintf('Unexpected HTTP request in the test environment: %s. Simulate it with a MockHttpClient, or with the recorded responses of the demo mode.', $request), $e->getMessage());
        }

        // Taken from the ledger: the request was expected here
        self::assertSame([$request], UnexpectedRequestGuard::takeRequests());
    }
}
