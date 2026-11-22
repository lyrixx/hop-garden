<?php

namespace App\Garden;

use App\DataSource\Exception\DataSourceUnavailableException;
use App\DataSource\Trefle\TrefleClient;
use App\Garden\Model\SpeciesSearchResult;
use Psr\Log\LoggerInterface;

/**
 * The species of a planting, from the name the user types. Trefle may not know the name, or not answer: the name is
 * kept as is.
 */
final readonly class SpeciesSearch
{
    private const int MIN_NAME_LENGTH = 2;
    private const int MAX_NAME_LENGTH = 100;

    public function __construct(
        private TrefleClient $trefleClient,
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
        } catch (DataSourceUnavailableException $e) {
            $this->logger->warning('Species search unavailable.', ['exception' => $e]);

            return SpeciesSearchResult::trefleUnavailable();
        }

        // Trefle knows the plants by their English or Latin names: the fallback for a French name ("rosier grimpant"), which
        // asks an LLM for its Latin name and searches again, plugs in here, when $candidates is empty.

        return SpeciesSearchResult::found($candidates);
    }
}
