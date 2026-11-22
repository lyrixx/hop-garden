<?php

namespace App\Chat\Model;

use Symfony\Component\Uid\Uuid;

final readonly class AgentRun
{
    /**
     * @param bool       $completed        false when the agent failed: an error was pushed instead of "done"
     * @param list<Uuid> $proposedPlantIds the drafts created by propose_plant, in order
     */
    public function __construct(
        public bool $completed,
        public array $proposedPlantIds,
    ) {
    }
}
