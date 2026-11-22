<?php

namespace App\Garden\Model;

use App\DataSource\Trefle\Model\PlantCandidate;

/**
 * The species found for the name of a planting. Trefle may know none of them, or not answer; the LLM may not be asked
 * for its Latin names, the quota of the garden spent.
 */
final readonly class SpeciesSearchResult
{
    /**
     * @param list<PlantCandidate> $candidates
     */
    private function __construct(
        public array $candidates,
        public bool $trefleUnavailable,
        public bool $latinNamesQuotaReached,
    ) {
    }

    /**
     * @param list<PlantCandidate> $candidates
     */
    public static function found(array $candidates): self
    {
        return new self($candidates, false, false);
    }

    public static function trefleUnavailable(): self
    {
        return new self([], true, false);
    }

    /**
     * Trefle found nothing for the name as typed, and the LLM was not asked for its Latin names.
     */
    public static function latinNamesQuotaReached(): self
    {
        return new self([], false, true);
    }

    public function find(int $trefleId): ?PlantCandidate
    {
        foreach ($this->candidates as $candidate) {
            if ($candidate->trefleId === $trefleId) {
                return $candidate;
            }
        }

        return null;
    }
}
