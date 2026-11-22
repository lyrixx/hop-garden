<?php

namespace App\Tests\Unit\DataSource\Trefle;

use App\DataSource\DataSourceCache;
use App\DataSource\Exception\DataSourceUnavailableException;
use App\DataSource\Trefle\Model\PlantCandidate;
use App\DataSource\Trefle\TrefleClient;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;

final class TrefleClientTest extends TestCase
{
    private TestHandler $logs;

    protected function setUp(): void
    {
        $this->logs = new TestHandler();
    }

    public function testTheCandidatesOfASearch(): void
    {
        $response = new JsonMockResponse(['data' => [
            ['id' => 191052, 'scientific_name' => 'Hydrangea paniculata', 'common_name' => 'Panicled hydrangea', 'family' => 'Hydrangeaceae', 'image_url' => 'https://bs.plantnet.org/paniculata.jpg', 'slug' => 'hydrangea-paniculata'],
            ['id' => 191047, 'scientific_name' => 'Hydrangea macrophylla', 'common_name' => null],
        ]]);
        $httpClient = new MockHttpClient($response, 'https://trefle.io');

        $candidates = $this->client($httpClient)->search('  hortensia ');

        self::assertSame('GET', $response->getRequestMethod());
        self::assertSame('https://trefle.io/api/v1/plants/search?q=hortensia&token=secret', $response->getRequestUrl(), 'Trimmed.');
        self::assertSame([
            [191052, 'Hydrangea paniculata', 'Panicled hydrangea', 'Hydrangeaceae', 'https://bs.plantnet.org/paniculata.jpg'],
            [191047, 'Hydrangea macrophylla', null, null, null],
        ], self::valuesOf($candidates));
    }

    public function testTenCandidatesAtMost(): void
    {
        $plants = array_map(static fn (int $id): array => ['id' => $id, 'scientific_name' => 'Rosa ' . $id], range(1, 12));

        $candidates = $this->client(new MockHttpClient(new JsonMockResponse(['data' => $plants]), 'https://trefle.io'))->search('rose');

        self::assertSame(range(1, 10), array_map(static fn (PlantCandidate $candidate): int => $candidate->trefleId, $candidates));
    }

    public function testNothingFound(): void
    {
        self::assertSame([], $this->client(new MockHttpClient(new JsonMockResponse(['data' => [], 'meta' => ['total' => 0]]), 'https://trefle.io'))->search('triffid'));
    }

    public function testASearchIsAskedOnce(): void
    {
        $httpClient = new MockHttpClient([new JsonMockResponse(['data' => [['id' => 1, 'scientific_name' => 'Rosa canina']]])], 'https://trefle.io');
        $client = $this->client($httpClient);

        $first = $client->search('Rose');
        $second = $client->search(' rose ');

        self::assertSame(1, $httpClient->getRequestsCount(), 'Same query, whatever its case and blanks.');
        self::assertSame(self::valuesOf($first), self::valuesOf($second));
    }

    public function testWithoutTokenTrefleIsNotConfigured(): void
    {
        $httpClient = new MockHttpClient([], 'https://trefle.io');

        try {
            $this->client($httpClient, token: '')->search('hortensia');
            self::fail('A search without token must fail.');
        } catch (DataSourceUnavailableException $e) {
            self::assertSame('Trefle is not configured: set TREFLE_TOKEN, or enable the demo mode with APP_DEMO=1.', $e->getMessage());
        }

        self::assertSame(0, $httpClient->getRequestsCount());
    }

    public function testTheDemoModeNeedsNoToken(): void
    {
        $httpClient = new MockHttpClient(new JsonMockResponse(['data' => []]), 'https://trefle.io');

        self::assertSame([], $this->client($httpClient, token: '', demo: true)->search('hortensia'));
    }

    #[DataProvider('provideAFailureIsUnavailableAndNotCachedCases')]
    public function testAFailureIsUnavailableAndNotCached(MockResponse $response): void
    {
        $httpClient = new MockHttpClient([$response, new JsonMockResponse(['data' => []])], 'https://trefle.io');
        $client = $this->client($httpClient);

        try {
            $client->search('hortensia');
            self::fail('The search must fail.');
        } catch (DataSourceUnavailableException $e) {
            self::assertStringStartsWith('Trefle ', $e->getMessage());
        }

        self::assertTrue($this->logs->hasRecordThatContains('Trefle search failed.', Level::Warning));
        self::assertSame('hortensia', $this->logs->getRecords()[0]->context['query']);
        self::assertSame([], $client->search('hortensia'), 'Asked again.');
        self::assertSame(2, $httpClient->getRequestsCount());
    }

    /**
     * @return iterable<string, array{MockResponse}>
     */
    public static function provideAFailureIsUnavailableAndNotCachedCases(): iterable
    {
        yield 'an error status' => [new JsonMockResponse(['error' => true, 'message' => 'Invalid access token'], ['http_code' => 401])];
        yield 'a server error' => [new MockResponse('Bad gateway', ['http_code' => 502])];
        yield 'not JSON' => [new MockResponse('<html>Maintenance</html>', ['response_headers' => ['content-type' => 'text/html']])];
        yield 'a timeout' => [new MockResponse([''])];
        yield 'no list of plants' => [new JsonMockResponse(['data' => 'none'])];
        yield 'a plant without id' => [new JsonMockResponse(['data' => [['scientific_name' => 'Rosa canina']]])];
        yield 'a plant without scientific name' => [new JsonMockResponse(['data' => [['id' => 1, 'scientific_name' => null]]])];
        yield 'a common name that is not a string' => [new JsonMockResponse(['data' => [['id' => 1, 'scientific_name' => 'Rosa canina', 'common_name' => ['en' => 'Dog rose']]]])];
    }

    /**
     * @param list<PlantCandidate> $candidates
     *
     * @return list<array{int, string, ?string, ?string, ?string}>
     */
    private static function valuesOf(array $candidates): array
    {
        return array_map(static fn (PlantCandidate $candidate): array => [$candidate->trefleId, $candidate->scientificName, $candidate->commonName, $candidate->family, $candidate->imageUrl], $candidates);
    }

    private function client(MockHttpClient $httpClient, string $token = 'secret', bool $demo = false): TrefleClient
    {
        return new TrefleClient($httpClient, new DataSourceCache(new ArrayAdapter(), $demo), new Logger('test', [$this->logs]), $token, $demo);
    }
}
