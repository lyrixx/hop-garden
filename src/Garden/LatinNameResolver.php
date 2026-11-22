<?php

namespace App\Garden;

use App\DataSource\DataSourceCache;
use App\DataSource\Demo\RecordedLatinNames;
use App\DataSource\Exception\UnexpectedResponseException;
use App\Garden\Exception\QuotaReachedException;
use Psr\Log\LoggerInterface;
use Symfony\AI\Platform\Exception\ExceptionInterface as PlatformException;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\PlatformOptions;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpException;

/**
 * The Latin names a vernacular name may stand for ("rosier" is Rosa), asked to the LLM in one short call: the fallback
 * of SpeciesSearch, for a name Trefle does not know. Cached per name and language, an answer without any name too; a
 * failure is not, it is asked again next time. Each question counts in the quota of the current garden (GardenQuotas).
 * In demo mode, and so in the tests, the names are recorded ones (RecordedLatinNames): no network, no quota.
 */
final readonly class LatinNameResolver
{
    private const int MAX_NAMES = 3;
    private const int MAX_OUTPUT_TOKENS = 100;
    private const int CACHE_TTL_IN_SECONDS = 30 * 24 * 3600;

    /**
     * @param non-empty-string $model the one of the chat agent
     */
    public function __construct(
        #[Autowire(service: 'app.ai.platform')]
        private PlatformInterface $platform,
        #[Autowire(env: 'AI_MODEL')]
        private string $model,
        private RecordedLatinNames $recordedLatinNames,
        private DataSourceCache $cache,
        private LoggerInterface $logger,
        #[Autowire(env: 'bool:APP_DEMO')]
        private bool $demo,
        private GardenQuotas $gardenQuotas,
        private CurrentGarden $currentGarden,
    ) {
    }

    /**
     * @param string $locale the language of the name
     *
     * @return list<string> the most likely first, none when the LLM knows no plant of that name or does not answer
     *
     * @throws QuotaReachedException
     */
    public function resolve(string $name, string $locale): array
    {
        try {
            return $this->cache->get('latin_names.' . $locale . '.' . mb_strtolower($name), self::CACHE_TTL_IN_SECONDS, fn (): array => $this->demo ? $this->recordedLatinNames->of($name) : $this->ask($name, $locale));
        } catch (PlatformException|HttpException|UnexpectedResponseException $e) {
            $this->logger->warning('The Latin names of a plant could not be asked to the LLM.', ['exception' => $e, 'name' => $name]);

            return [];
        }
    }

    /**
     * @return list<string>
     */
    private function ask(string $name, string $locale): array
    {
        if (!$this->gardenQuotas->consumeLatinNames($this->currentGarden->get())) {
            throw QuotaReachedException::forLatinNames();
        }

        $language = \Locale::getDisplayLanguage($locale, 'en');
        $maxNames = self::MAX_NAMES;
        $answer = $this->platform->invoke($this->model, new MessageBag(
            Message::forSystem(<<<PROMPT
                A gardener types the name of a plant, in {$language}. Give the Latin names it may stand for: the genus, or
                the genus and the species, the most likely first, {$maxNames} at most. Answer with a JSON array of
                strings and nothing else, such as ["Rosa"]; answer [] when it is not the name of a plant.
                PROMPT),
            Message::ofUser($name),
        ), [PlatformOptions::MAX_OUTPUT_TOKENS => self::MAX_OUTPUT_TOKENS])->asText();

        return self::latinNames($answer);
    }

    /**
     * @return list<string>
     */
    private static function latinNames(string $answer): array
    {
        // A model may wrap its JSON in a code block
        if (1 !== preg_match('/\[.*]/s', $answer, $json)) {
            throw new UnexpectedResponseException(\sprintf('The LLM did not answer with a list of Latin names: "%s".', $answer));
        }

        $names = json_decode($json[0], true);
        if (!\is_array($names)) {
            throw new UnexpectedResponseException(\sprintf('The LLM did not answer with a list of Latin names: "%s".', $answer));
        }

        $latinNames = [];
        foreach ($names as $latinName) {
            if (\is_string($latinName) && '' !== trim($latinName)) {
                $latinNames[] = trim($latinName);
            }
        }

        return \array_slice(array_values(array_unique($latinNames)), 0, self::MAX_NAMES);
    }
}
