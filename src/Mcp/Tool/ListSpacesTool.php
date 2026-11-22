<?php

namespace App\Mcp\Tool;

use App\Garden\CurrentGarden;
use App\Garden\SpaceRepository;
use App\Mcp\GardenNormalizer;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\ToolAnnotations;

#[McpTool(
    name: 'list_spaces',
    title: 'List the spaces of the garden',
    description: <<<'TXT'
        List the spaces of the user's garden (the terrace, the north bed...): their id, their exposure, their soil and the plants growing there.
        The names are the user's own, in any language: match them by meaning, not by spelling ("terrasse" is the space "Terrace").
        TXT,
    annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: false),
    outputSchema: [
        'type' => 'object',
        'properties' => [
            'spaces' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'id' => ['type' => 'string', 'format' => 'uuid'],
                        'name' => ['type' => 'string'],
                        'exposure' => ['type' => ['string', 'null']],
                        'soil' => ['type' => ['string', 'null']],
                        'plantings' => ['type' => 'array', 'items' => ['type' => 'string']],
                    ],
                    'required' => ['id', 'name', 'exposure', 'soil', 'plantings'],
                ],
            ],
        ],
        'required' => ['spaces'],
    ],
)]
final readonly class ListSpacesTool
{
    public function __construct(
        private SpaceRepository $spaceRepository,
        private GardenNormalizer $gardenNormalizer,
        private CurrentGarden $currentGarden,
    ) {
    }

    /**
     * @return array{spaces: list<array<string, mixed>>}
     */
    public function __invoke(): array
    {
        return [
            'spaces' => array_map($this->gardenNormalizer->space(...), $this->spaceRepository->findAllByName($this->currentGarden->get())),
        ];
    }
}
