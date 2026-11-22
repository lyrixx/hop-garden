<?php

namespace App\Mcp\Tool;

use App\Garden\CurrentGarden;
use App\Mcp\CareSheetInput;
use App\Mcp\PlantNormalizer;
use App\Plant\Entity\Plant;
use App\Plant\Exception\InvalidCareSheetException;
use App\Plant\PlantRepository;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;
use Psr\Clock\ClockInterface;

#[McpTool(
    name: 'propose_plant',
    title: 'Propose a plant for the garden',
    description: <<<'TXT'
        Last step of adding a plant: create a DRAFT care sheet for the user to review.
        The plant is NOT validated yet: the user reviews the draft card, may edit it, and validates it themselves.
        Never tell the user the plant is added; tell them to review and validate the card.
        TXT,
    annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: false),
    outputSchema: [
        'type' => 'object',
        'properties' => [
            'plant_id' => ['type' => 'string', 'format' => 'uuid'],
            'status' => ['type' => 'string', 'enum' => ['draft']],
            'validation_url' => ['type' => 'string'],
            'next_step' => ['type' => 'string'],
        ],
        'required' => ['plant_id', 'status', 'validation_url', 'next_step'],
    ],
)]
final readonly class ProposePlantTool
{
    public function __construct(
        private PlantRepository $plantRepository,
        private EntityManagerInterface $entityManager,
        private PlantNormalizer $plantNormalizer,
        private ClockInterface $clock,
        private CurrentGarden $currentGarden,
    ) {
    }

    /**
     * @param array<string, mixed> $care_sheet
     *
     * @return array<string, mixed>
     */
    public function __invoke(
        #[Schema(description: 'Common name, in the language of the interface of Hop Garden when you know it, else of the user. Example: "Panicled hydrangea".', minLength: 1, maxLength: 100)]
        string $common_name,
        #[Schema(description: 'Scientific name. Example: "Hydrangea paniculata".', minLength: 3, maxLength: 150)]
        string $scientific_name,
        #[Schema(description: 'Botanical family. Example: "Hydrangeaceae".', minLength: 3, maxLength: 100)]
        string $family,
        #[Schema(definition: CareSheetInput::SCHEMA)]
        array $care_sheet,
        #[Schema(description: 'Trefle id of the species, from search_plant. Omit it when search_plant found nothing.', minimum: 1)]
        ?int $trefle_id = null,
        #[Schema(description: 'Image URL from search_plant, if any.', format: 'uri', maxLength: 2048)]
        ?string $image_url = null,
        #[Schema(description: 'Anything the user should know about this plant, in one or two sentences, in the language of the common name.', maxLength: 1000)]
        ?string $notes = null,
    ): array {
        try {
            $careSheet = CareSheetInput::toCareSheet($care_sheet);
        } catch (InvalidCareSheetException $e) {
            throw new ToolCallException($e->getMessage());
        }

        $plant = Plant::propose($this->currentGarden->get(), trim($common_name), trim($scientific_name), trim($family), $careSheet, $this->clock->now(), $trefle_id, $image_url, $notes);
        $this->plantRepository->add($plant);
        $this->entityManager->flush();

        return [
            'plant_id' => $plant->getId()->toRfc4122(),
            'status' => $plant->getStatus()->value,
            'validation_url' => $this->plantNormalizer->urlOf($plant),
            'next_step' => 'The draft card is shown to the user. Ask them to review the highlighted fields and to validate it: until then, the plant is not validated.',
        ];
    }
}
