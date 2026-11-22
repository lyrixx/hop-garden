<?php

namespace App\Garden\Model;

use App\Plant\Model\Exposure;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * A space the agent proposes to create, on a card the user completes and taps: nothing is written until then. Its id
 * is the key of the card: the space created from it keeps it, so that the card says "Created", once.
 *
 * The same keys everywhere: the result of propose_space, the history of the chat, the URL of the card.
 */
final readonly class SpaceProposal
{
    public function __construct(
        public string $proposalId,
        public string $name,
        public ?Exposure $exposure,
        public ?Soil $soil,
    ) {
    }

    public static function create(string $name, ?Exposure $exposure, ?Soil $soil): self
    {
        return new self((new UuidV7())->toRfc4122(), $name, $exposure, $soil);
    }

    /**
     * @param array<mixed> $data as toArray() writes it, read again from the history of the chat
     *
     * @return ?self null for a malformed result
     */
    public static function fromArray(array $data): ?self
    {
        $proposalId = $data['proposal_id'] ?? null;
        $name = $data['name'] ?? null;
        $exposure = $data['exposure'] ?? null;
        $soil = $data['soil'] ?? null;

        if (!\is_string($proposalId) || !Uuid::isValid($proposalId) || !\is_string($name) || (null !== $exposure && !\is_string($exposure)) || (null !== $soil && !\is_string($soil))) {
            return null;
        }

        return new self($proposalId, $name, null !== $exposure ? Exposure::tryFrom($exposure) : null, null !== $soil ? Soil::tryFrom($soil) : null);
    }

    /**
     * @return array{proposal_id: string, name: string, exposure: ?string, soil: ?string}
     */
    public function toArray(): array
    {
        return [
            'proposal_id' => $this->proposalId,
            'name' => $this->name,
            'exposure' => $this->exposure?->value,
            'soil' => $this->soil?->value,
        ];
    }
}
