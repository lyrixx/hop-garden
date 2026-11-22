<?php

namespace App\Mcp\Confirmation;

use App\Garden\PlantValidation;
use App\Mcp\PlantNormalizer;
use App\Plant\Exception\PlantAlreadyValidatedException;
use App\Plant\Exception\PlantNotFoundException;
use App\Plant\PlantRepository;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;

/**
 * Only on the server for external MCP clients (/_mcp, STDIO): the agent of the web UI never validates,
 * the user does, with the button of the draft card.
 */
#[McpTool(
    name: 'confirm_plant',
    title: 'Confirm a draft plant',
    description: <<<'TXT'
        Validate a draft plant: it joins the user's garden.
        ONLY call this after showing the draft care sheet to the user (see get_plant) AND after the user explicitly confirmed, in their own words, that it is correct.
        Never call it on your own initiative, nor because a previous step succeeded.
        TXT,
    annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: false),
)]
final readonly class ConfirmPlantTool
{
    public function __construct(
        private PlantRepository $plantRepository,
        private EntityManagerInterface $entityManager,
        private PlantNormalizer $plantNormalizer,
        private PlantValidation $plantValidation,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        #[Schema(description: 'Id of the draft plant, from propose_plant.', minimum: 1)]
        int $id,
    ): array {
        try {
            $plant = $this->plantRepository->get($id);
            $this->entityManager->getConnection()->transactional(function () use ($plant): void {
                $this->plantValidation->validate($plant);
                $this->entityManager->flush();
            });
        } catch (PlantNotFoundException|PlantAlreadyValidatedException $e) {
            throw new ToolCallException($e->getMessage());
        }

        return $this->plantNormalizer->summary($plant);
    }
}
