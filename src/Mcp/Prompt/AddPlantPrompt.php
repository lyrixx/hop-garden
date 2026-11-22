<?php

namespace App\Mcp\Prompt;

use Mcp\Capability\Attribute\McpPrompt;
use Mcp\Capability\Attribute\Schema;

#[McpPrompt(
    name: 'add_plant',
    title: 'Add a plant',
    description: 'Identify a plant, complete its care sheet and propose it as a draft for the user to confirm.',
)]
final readonly class AddPlantPrompt
{
    /**
     * @return list<array{role: string, content: string}>
     */
    public function __invoke(
        #[Schema(description: 'The plant, as the user names it. Example: "hortensia".')]
        string $name,
    ): array {
        return [[
            'role' => 'user',
            'content' => <<<TXT
                Add "{$name}" to my garden in Hop Garden:

                1. Call search_plant with "{$name}". If several candidates are credible, ask me which one I have (3 choices at most) and wait for my answer.
                2. Complete the care sheet with your horticultural knowledge for a temperate Western European climate: hardiness, frost sensitivity, exposure, watering, the months to prune, deadhead and take cuttings.
                   Be honest in the confidence of each field: "high" only for well-established rules, "low" for any uncertain number. List your sources, "LLM knowledge" included.
                3. Call propose_plant: it creates a draft, not a validated plant of my garden.
                4. Show me the draft and its uncertain fields. Call confirm_plant only once I explicitly confirmed it.
                TXT,
        ]];
    }
}
