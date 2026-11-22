<?php

namespace App\Garden\Model;

use Symfony\Component\Uid\Uuid;

/**
 * A plant the agent proposes to add to the garden, on an "Add to my garden" card: nothing is written until the user
 * taps it. Its id is the key of the card: the planting added from it keeps it, so that the card says "Added", once.
 *
 * The same keys everywhere: the result of propose_planting, the history of the chat, the URL of the card.
 */
final readonly class PlantingProposal
{
    public function __construct(
        public Uuid $proposalId,
        public Uuid $plantId,
        public ?Uuid $spaceId,
        public string $name,
    ) {
    }

    public static function create(Uuid $plantId, ?Uuid $spaceId, string $name): self
    {
        return new self(Uuid::v7(), $plantId, $spaceId, $name);
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

        if (!self::isUuid($proposalId) || !self::isUuid($plantId) || (null !== $spaceId && !self::isUuid($spaceId)) || !\is_string($name)) {
            return null;
        }

        return new self(Uuid::fromString($proposalId), Uuid::fromString($plantId), null !== $spaceId ? Uuid::fromString($spaceId) : null, $name);
    }

    /**
     * @return array{proposal_id: string, plant_id: string, space_id: ?string, name: string}
     */
    public function toArray(): array
    {
        return [
            'proposal_id' => $this->proposalId->toRfc4122(),
            'plant_id' => $this->plantId->toRfc4122(),
            'space_id' => $this->spaceId?->toRfc4122(),
            'name' => $this->name,
        ];
    }

    /**
     * @phpstan-assert-if-true string $value
     */
    private static function isUuid(mixed $value): bool
    {
        return \is_string($value) && Uuid::isValid($value);
    }
}
