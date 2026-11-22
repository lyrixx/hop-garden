<?php

namespace App\Garden\Model;

use App\DataSource\Trefle\Model\PlantCandidate;

/**
 * The species found for the name of a planting. Trefle may know none of them, or not answer.
 */
final readonly class SpeciesSearchResult
{
    /**
     * @param list<PlantCandidate> $candidates
     */
    private function __construct(
        public array $candidates,
        public bool $trefleUnavailable,
    ) {
    }

    /**
     * @param list<PlantCandidate> $candidates
     */
    public static function found(array $candidates): self
    {
        return new self($candidates, false);
    }

    public static function trefleUnavailable(): self
    {
        return new self([], true);
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
