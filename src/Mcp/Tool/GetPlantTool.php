<?php

namespace App\Mcp\Tool;

use App\Garden\CurrentGarden;
use App\Mcp\PlantNormalizer;
use App\Plant\Exception\PlantNotFoundException;
use App\Plant\PlantRepository;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;

#[McpTool(
    name: 'get_plant',
    title: 'Get a plant',
    description: 'Get a plant (draft or validated) with its full care sheet, the confidence of each field, its sources and its enabled reminders.',
    annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: false),
)]
final readonly class GetPlantTool
{
    public function __construct(
        private PlantRepository $plantRepository,
        private PlantNormalizer $plantNormalizer,
        private CurrentGarden $currentGarden,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        #[Schema(description: 'Id of the plant, from list_collection or propose_plant.', minimum: 1)]
        int $id,
    ): array {
        try {
            return $this->plantNormalizer->detail($this->plantRepository->get($this->currentGarden->get(), $id));
        } catch (PlantNotFoundException $e) {
            throw new ToolCallException($e->getMessage());
        }
    }
}
