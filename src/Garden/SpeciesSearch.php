<?php

namespace App\Garden;

use App\DataSource\Exception\DataSourceUnavailableException;
use App\DataSource\Trefle\Model\PlantCandidate;
use App\DataSource\Trefle\TrefleClient;
use App\Garden\Model\SpeciesSearchResult;
use App\Locale\Model\SupportedLocale;
use Psr\Log\LoggerInterface;
use Symfony\Component\Translation\LocaleSwitcher;

/**
 * The species of a planting, from the name the user types. Trefle may not know the name, or not answer: the name is
 * kept as is.
 */
final readonly class SpeciesSearch
{
    private const int MIN_NAME_LENGTH = 2;
    private const int MAX_NAME_LENGTH = 100;
    /**
     * The LLM is asked for a whole word, not for the first letters typed.
     */
    private const int MIN_FALLBACK_NAME_LENGTH = 4;
    private const int MAX_CANDIDATES = 10;

    public function __construct(
        private TrefleClient $trefleClient,
        private LatinNameResolver $latinNameResolver,
        private LocaleSwitcher $localeSwitcher,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Null when the name is too short to search.
     */
    public function search(string $name): ?SpeciesSearchResult
    {
        $name = mb_substr(trim($name), 0, self::MAX_NAME_LENGTH);
        if (mb_strlen($name) < self::MIN_NAME_LENGTH) {
            return null;
        }

        try {
            $candidates = $this->trefleClient->search($name);
            if ([] === $candidates) {
                $candidates = $this->searchByLatinNames($name);
            }
        } catch (DataSourceUnavailableException $e) {
            $this->logger->warning('Species search unavailable.', ['exception' => $e]);

            return SpeciesSearchResult::trefleUnavailable();
        }

        return SpeciesSearchResult::found($candidates);
    }

    /**
     * Trefle knows the plants by their English or Latin names: a name in the language of the interface, when it is not
     * English ("rosier grimpant"), gets its Latin names from the LLM, searched in turn. Only when Trefle finds nothing for
     * the name as typed, and for a whole word: a fallback, cached per name (LatinNameResolver), not a call per keystroke.
     *
     * @return list<PlantCandidate>
     */
    private function searchByLatinNames(string $name): array
    {
        $locale = $this->localeSwitcher->getLocale();
        if (SupportedLocale::English->value === $locale || mb_strlen($name) < self::MIN_FALLBACK_NAME_LENGTH) {
            return [];
        }

        $candidates = [];
        foreach ($this->latinNameResolver->resolve($name, $locale) as $latinName) {
            foreach ($this->trefleClient->search($latinName) as $candidate) {
                $candidates[$candidate->trefleId] ??= $candidate;
            }
        }

        return \array_slice(array_values($candidates), 0, self::MAX_CANDIDATES);
    }
}
