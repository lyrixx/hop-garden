<?php

namespace App\Mcp\Tool;

use App\Garden\CurrentGarden;
use App\Mcp\PlantNormalizer;
use App\Plant\PlantRepository;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\ToolAnnotations;

#[McpTool(
    name: 'list_collection',
    title: 'List the collection',
    description: 'List the validated plants of the user\'s garden (one care sheet per species, drafts excluded) with their enabled reminders.',
    annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: false),
)]
final readonly class ListCollectionTool
{
    public function __construct(
        private PlantRepository $plantRepository,
        private PlantNormalizer $plantNormalizer,
        private CurrentGarden $currentGarden,
    ) {
    }

    /**
     * @return array{plants: list<array<string, mixed>>}
     */
    public function __invoke(): array
    {
        return [
            'plants' => array_map($this->plantNormalizer->summary(...), $this->plantRepository->findValidated($this->currentGarden->get())),
        ];
    }
}
