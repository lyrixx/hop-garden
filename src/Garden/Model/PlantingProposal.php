<?php

namespace App\Garden\Model;

use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * A plant the agent proposes to add to the garden, on an "Add to my garden" card: nothing is written until the user
 * taps it. Its id is the key of the card: the planting added from it keeps it, so that the card says "Added", once.
 *
 * The same keys everywhere: the result of propose_planting, the history of the chat, the URL of the card.
 */
final readonly class PlantingProposal
{
    public function __construct(
        public string $proposalId,
        public int $plantId,
        public ?int $spaceId,
        public string $name,
    ) {
    }

    public static function create(int $plantId, ?int $spaceId, string $name): self
    {
        return new self((new UuidV7())->toRfc4122(), $plantId, $spaceId, $name);
    }

    /**
     * @param array<mixed> $data as toArray() writes it, read again from the history of the chat
     *
     * @return ?self null for a malformed result
     */
    public static function fromArray(array $data): ?self
    {
        $proposalId = $data['proposal_id'] ?? null;
        $plantId = $data['plant_id'] ?? null;
        $spaceId = $data['space_id'] ?? null;
        $name = $data['name'] ?? null;

        if (!\is_string($proposalId) || !Uuid::isValid($proposalId) || !\is_int($plantId) || (null !== $spaceId && !\is_int($spaceId)) || !\is_string($name)) {
            return null;
        }

        return new self($proposalId, $plantId, $spaceId, $name);
    }

    /**
     * @return array{proposal_id: string, plant_id: int, space_id: ?int, name: string}
     */
    public function toArray(): array
    {
        return [
            'proposal_id' => $this->proposalId,
            'plant_id' => $this->plantId,
            'space_id' => $this->spaceId,
            'name' => $this->name,
        ];
    }
}
