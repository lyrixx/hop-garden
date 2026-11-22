<?php

namespace App\Mcp\Confirmation;

use App\Garden\CurrentGarden;
use App\Garden\PlantValidation;
use App\Garden\Security\PlantValidationVoter;
use App\Mcp\PlantNormalizer;
use App\Plant\Exception\PlantAlreadyValidatedException;
use App\Plant\Exception\PlantNotFoundException;
use App\Plant\PlantRepository;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Only on the server for external MCP clients (/_mcp, STDIO): the agent of the web UI never validates,
 * the user does, with the button of the draft card.
 */
#[McpTool(
    name: 'confirm_plant',
    title: 'Confirm a draft plant',
    description: <<<'TXT'
        Validate a draft care sheet. It adds no plant to the user's garden: ask them where it grows, then call propose_planting.
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
        private CurrentGarden $currentGarden,
        private AuthorizationCheckerInterface $authorizationChecker,
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
            $plant = $this->plantRepository->get($this->currentGarden->get(), $id);
            if (!$this->authorizationChecker->isGranted(PlantValidationVoter::VALIDATE, $plant)) {
                throw new ToolCallException('Only the user validates a care sheet, in Hop Garden.');
            }
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
