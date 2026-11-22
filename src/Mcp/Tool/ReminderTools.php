<?php

namespace App\Mcp\Tool;

use App\Plant\Exception\PlantNotFoundException;
use App\Plant\Exception\PlantNotValidatedException;
use App\Plant\PlantRepository;
use App\Reminder\Model\ReminderType;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;

final readonly class ReminderTools
{
    private const string TYPE_DESCRIPTION = '"pruning", "deadheading" and "cutting" remind on the first day of the months of the care sheet; "frost" warns when the 3-day forecast gets colder than the hardiness of the plant.';

    public function __construct(
        private PlantRepository $plantRepository,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return array{plant_id: int, enabled_reminders: list<string>}
     */
    #[McpTool(
        name: 'enable_reminder',
        title: 'Enable a reminder',
        description: 'Turn on a reminder for a validated plant. Refused for a draft: the user must validate it first.',
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false),
    )]
    public function enable(
        #[Schema(description: 'Id of a validated plant.', minimum: 1)]
        int $plant_id,
        #[Schema(description: self::TYPE_DESCRIPTION)]
        ReminderType $type,
    ): array {
        return $this->change($plant_id, static fn ($plant) => $plant->enableReminder($type));
    }

    /**
     * @return array{plant_id: int, enabled_reminders: list<string>}
     */
    #[McpTool(
        name: 'disable_reminder',
        title: 'Disable a reminder',
        description: 'Turn off a reminder of a validated plant. Refused for a draft.',
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false),
    )]
    public function disable(
        #[Schema(description: 'Id of a validated plant.', minimum: 1)]
        int $plant_id,
        #[Schema(description: self::TYPE_DESCRIPTION)]
        ReminderType $type,
    ): array {
        return $this->change($plant_id, static fn ($plant) => $plant->disableReminder($type));
    }

    /**
     * @param callable(\App\Plant\Entity\Plant): mixed $change
     *
     * @return array{plant_id: int, enabled_reminders: list<string>}
     */
    private function change(int $plantId, callable $change): array
    {
        try {
            $plant = $this->plantRepository->get($plantId);
            $change($plant);
        } catch (PlantNotFoundException|PlantNotValidatedException $e) {
            throw new ToolCallException($e->getMessage());
        }

        $this->entityManager->flush();

        return [
            'plant_id' => $plant->getId(),
            'enabled_reminders' => array_map(static fn (ReminderType $type): string => $type->value, $plant->getEnabledReminderTypes()),
        ];
    }
}
