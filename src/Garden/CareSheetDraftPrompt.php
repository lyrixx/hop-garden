<?php

namespace App\Garden;

use App\Garden\Entity\Planting;

/**
 * What the gardener agent is asked when a plant of the garden needs a care sheet. Nobody answers in this run: the
 * species the user picked is given, and the agent must not ask anything.
 */
final class CareSheetDraftPrompt
{
    public static function for(Planting $planting): string
    {
        $prompt = \sprintf('Draft the care sheet of a plant of my garden, which I call "%s".', str_replace('"', "'", $planting->getName()));
        $scientificName = $planting->getScientificName();

        if (null !== $scientificName) {
            $prompt .= null !== $planting->getTrefleId()
                ? \sprintf(' I chose its species: %s (Trefle id %d).', $scientificName, $planting->getTrefleId())
                : \sprintf(' I chose its species: %s.', $scientificName);

            return $prompt . ' This runs without a chat: nobody can answer you, so ask no question. The plant is in my garden already: propose no space nor planting, and finish with hopgarden_propose_plant for this species.';
        }

        return $prompt . ' I do not know its species. This runs without a chat: nobody can answer you, so ask no question.'
            . ' The plant is in my garden already: propose no space nor planting.'
            . ' Identify it with hopgarden_search_plant and go on with the most likely candidate, or with your own knowledge'
            . ' and a low confidence if there is none, then finish with hopgarden_propose_plant.';
    }
}
