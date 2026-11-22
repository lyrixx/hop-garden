<?php

namespace App\Mcp\Tool;

use App\Garden\CurrentGarden;
use App\Garden\Model\Soil;
use App\Garden\Model\SpaceProposal;
use App\Garden\SpaceRepository;
use App\Mcp\GardenNormalizer;
use App\Plant\Model\Exposure;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;

/**
 * Writes nothing: the space is created when the user taps its card, see App\Controller\ProposalController.
 */
#[McpTool(
    name: 'propose_space',
    title: 'Propose a new space of the garden',
    description: <<<'TXT'
        Propose a NEW space of the garden (a spot such as the terrace or the north bed) as a card: the user completes its exposure and its soil by tapping buttons, then creates it.
        Nothing is created until they do: never say the space exists before they tell you so.
        Call list_spaces first: never propose a space the garden has already, under any spelling or language.
        TXT,
    annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: false),
    outputSchema: [
        'type' => 'object',
        'properties' => [
            'proposal_id' => ['type' => 'string'],
            'name' => ['type' => 'string'],
            'exposure' => ['type' => ['string', 'null']],
            'soil' => ['type' => ['string', 'null']],
            'validation_url' => ['type' => 'string'],
            'next_step' => ['type' => 'string'],
        ],
        'required' => ['proposal_id', 'name', 'exposure', 'soil', 'validation_url', 'next_step'],
    ],
)]
final readonly class ProposeSpaceTool
{
    public function __construct(
        private SpaceRepository $spaceRepository,
        private GardenNormalizer $gardenNormalizer,
        private CurrentGarden $currentGarden,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        #[Schema(description: 'Name of the space as the user calls it, in their language. Example: "Terrasse".', minLength: 1, maxLength: 100)]
        string $name,
        #[Schema(description: 'Its exposure, only when the user said it: "sun" (full sun), "part_shade" or "shade". Omit it otherwise: the user picks it on the card.')]
        ?Exposure $exposure = null,
        #[Schema(description: 'Its soil, only when the user said it: "chalky", "clay", "loamy", "peaty", "sandy" or "silty". Omit it otherwise: the user picks it on the card.')]
        ?Soil $soil = null,
    ): array {
        $garden = $this->currentGarden->get();
        $existing = $this->spaceRepository->findOneByName($garden, trim($name));
        if (null !== $existing) {
            throw new ToolCallException(\sprintf('The space "%s" exists already (id %s): propose the plant in it with propose_planting.', $existing->getName(), $existing->getId()));
        }

        return $this->gardenNormalizer->spaceProposal(SpaceProposal::create(trim($name), $exposure, $soil), $garden) + [
            'next_step' => 'The card is shown to the user: they pick the exposure and the soil, then create the space. Until they say so, the space does not exist. Then call list_spaces for its id, and propose_planting.',
        ];
    }
}
