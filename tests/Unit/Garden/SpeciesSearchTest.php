<?php

namespace App\Tests\Unit\Garden;

use App\DataSource\DataSourceCache;
use App\DataSource\Demo\RecordedLatinNames;
use App\DataSource\Demo\RecordedResponses;
use App\DataSource\Trefle\Model\PlantCandidate;
use App\DataSource\Trefle\TrefleClient;
use App\Factory\GardenFactory;
use App\Garden\LatinNameResolver;
use App\Garden\Model\SpeciesSearchResult;
use App\Garden\SpeciesSearch;
use App\Tests\Utils\Garden\FixedCurrentGarden;
use App\Tests\Utils\Garden\InMemoryQuotas;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\Test\MockPlatformFactory;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\Translation\LocaleSwitcher;

/**
 * The fallback on the Latin names, with the recorded Trefle searches and a simulated LLM.
 */
final class SpeciesSearchTest extends TestCase
{
    /** @var list<string> */
    private array $trefleQueries = [];

    /** @var list<string> */
    private array $llmQuestions = [];

    public function testAFrenchNameTrefleDoesNotKnowIsSearchedByItsLatinNames(): void
    {
        $result = $this->search('fr', 'rosier', '["Rosa", "Hydrangea"]');

        self::assertSame(['rosier', 'Rosa', 'Hydrangea'], $this->trefleQueries);
        self::assertSame(['rosier'], $this->llmQuestions);
        self::assertSame(
            ['Rosa canina', 'Rosa gallica', 'Rosa rugosa', 'Hydrangea macrophylla', 'Hydrangea paniculata', 'Hydrangea arborescens'],
            array_map(static fn (PlantCandidate $candidate): string => $candidate->scientificName, $result?->candidates ?? []),
        );
        self::assertNotNull($result?->find(263311), 'A candidate to pick, as any other.');
    }

    public function testTheLlmIsNotAskedWhenTrefleKnowsTheName(): void
    {
        $result = $this->search('fr', 'lavande', '["Lavandula"]');

        self::assertNotEmpty($result?->candidates);
        self::assertSame([], $this->llmQuestions);
    }

    public function testNorInEnglish(): void
    {
        $result = $this->search('en', 'rosier', '["Rosa"]');

        self::assertSame([], $result?->candidates);
        self::assertSame([], $this->llmQuestions, 'Trefle knows the plants by their English names.');
    }

    #[TestWith(['ros'])]
    #[TestWith(['  ro  '])]
    public function testNorForTheFirstLettersTyped(string $name): void
    {
        $this->search('fr', $name, '["Rosa"]');

        self::assertSame([], $this->llmQuestions);
    }

    public function testTheSameSpeciesOnce(): void
    {
        // Both are recorded as the same search
        $result = $this->search('fr', 'églantier', '["Rosa", "Rose"]');

        self::assertSame(['Rosa canina', 'Rosa gallica', 'Rosa rugosa'], array_map(static fn (PlantCandidate $candidate): string => $candidate->scientificName, $result?->candidates ?? []));
    }

    private function search(string $locale, string $name, string $llmAnswer): ?SpeciesSearchResult
    {
        $recordedResponses = new RecordedResponses(\dirname(__DIR__, 3) . '/fixtures/http', new MockClock());
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use ($recordedResponses) {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            $this->trefleQueries[] = \is_string($query['q'] ?? null) ? $query['q'] : '';

            return $recordedResponses($method, $url, $options);
        }, 'https://trefle.io');
        $cache = new DataSourceCache(new ArrayAdapter(), false);
        $platform = MockPlatformFactory::createPlatform(function (mixed $model, array|string|object $payload) use ($llmAnswer): TextResult {
            $messages = \is_array($payload) && \is_array($payload['messages'] ?? null) ? $payload['messages'] : [];
            $this->llmQuestions[] = (string) ($messages[1]['content'][0]['text'] ?? $messages[1]['content'] ?? '');

            return new TextResult($llmAnswer);
        });

        return new SpeciesSearch(
            new TrefleClient($httpClient, $cache, new NullLogger(), 'token', false),
            new LatinNameResolver($platform, 'claude-sonnet-5-5', new RecordedLatinNames(\dirname(__DIR__, 3) . '/fixtures/llm/latin-names.json'), $cache, new NullLogger(), false, InMemoryQuotas::create(), new FixedCurrentGarden(GardenFactory::createOne())),
            new LocaleSwitcher($locale, []),
            new NullLogger(),
        )->search($name);
    }
}
