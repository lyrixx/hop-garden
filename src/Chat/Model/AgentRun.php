<?php

namespace App\Chat\Model;

final readonly class AgentRun
{
    /**
     * @param bool      $completed        false when the agent failed: an error was pushed instead of "done"
     * @param list<int> $proposedPlantIds the drafts created by propose_plant, in order
     */
    public function __construct(
        public bool $completed,
        public array $proposedPlantIds,
    ) {
    }
}
