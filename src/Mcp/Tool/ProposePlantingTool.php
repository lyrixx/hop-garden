<?php

namespace App\Mcp\Tool;

use App\Garden\CurrentGarden;
use App\Garden\Model\PlantingProposal;
use App\Garden\SpaceRepository;
use App\Mcp\GardenNormalizer;
use App\Plant\Exception\PlantNotFoundException;
use App\Plant\PlantRepository;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;
use Symfony\Component\Uid\Uuid;

/**
 * Writes nothing: the plant is added when the user taps its card, see App\Controller\ProposalController.
 */
#[McpTool(
    name: 'propose_planting',
    title: 'Propose to add a plant to the garden',
    description: <<<'TXT'
        Propose to add a plant the user grows to their garden, as an "Add to my garden" card: its species (a validated care sheet), its space and its name.
        Nothing is added until the user taps the card: never say the plant is in the garden before they tell you so.
        The species must be validated: a draft from propose_plant is validated by the user first, on its card.
        TXT,
    annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: false),
    outputSchema: [
        'type' => 'object',
        'properties' => [
            'proposal_id' => ['type' => 'string'],
            'plant_id' => ['type' => 'string', 'format' => 'uuid'],
            'space_id' => ['type' => ['string', 'null'], 'format' => 'uuid'],
            'name' => ['type' => 'string'],
            'validation_url' => ['type' => 'string'],
            'next_step' => ['type' => 'string'],
        ],
        'required' => ['proposal_id', 'plant_id', 'space_id', 'name', 'validation_url', 'next_step'],
    ],
)]
final readonly class ProposePlantingTool
{
    public function __construct(
        private PlantRepository $plantRepository,
        private SpaceRepository $spaceRepository,
        private GardenNormalizer $gardenNormalizer,
        private CurrentGarden $currentGarden,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        #[Schema(description: 'Id of the validated care sheet of its species, a UUID: from list_collection, or the plant_id of propose_plant once the user validated it.', format: 'uuid')]
        string $plant_id,
        #[Schema(description: 'What the user calls this plant, in their language. Example: "Hortensia".', minLength: 1, maxLength: 100)]
        string $name,
        #[Schema(description: 'Id of the space where it grows, a UUID, from list_spaces. Omit it only when the user does not know yet.', format: 'uuid')]
        ?string $space_id = null,
    ): array {
        $garden = $this->currentGarden->get();

        try {
            $plant = $this->plantRepository->get($garden, $plant_id);
        } catch (PlantNotFoundException $e) {
            throw new ToolCallException($e->getMessage());
        }

        if (!$plant->isValidated()) {
            throw new ToolCallException(\sprintf('The care sheet of %s (id %s) is a draft: the user validates its card first.', $plant->getCommonName(), $plant->getId()));
        }

        // Malformed, of another garden or unknown: the same answer
        $space = null !== $space_id && Uuid::isValid($space_id) ? $this->spaceRepository->findOneInGarden($garden, Uuid::fromString($space_id)) : null;
        if (null !== $space_id && null === $space) {
            throw new ToolCallException(\sprintf('No space with id %s: call list_spaces.', $space_id));
        }

        return $this->gardenNormalizer->plantingProposal(PlantingProposal::create($plant->getId(), $space?->getId(), trim($name)), $garden) + [
            'next_step' => 'The "Add to my garden" card is shown to the user. Until they tap it, the plant is not in the garden.',
        ];
    }
}
