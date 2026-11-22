<?php

namespace App\Chat\Model;

use App\Garden\Model\PlantingProposal;
use App\Garden\Model\SpaceProposal;
use Symfony\Component\Uid\Uuid;

/**
 * A line of the chat history, as the template shows it.
 */
final readonly class ChatEntry
{
    public const string USER = 'user';
    public const string ASSISTANT = 'assistant';
    public const string TOOL_CALL = 'tool_call';
    public const string DRAFT_CARD = 'draft_card';
    public const string CHOICES = 'choices';
    public const string PLANTING_CARD = 'planting_card';
    public const string SPACE_CARD = 'space_card';

    /**
     * @param bool $answered for choices: a message of the user came after them, their buttons are off
     */
    private function __construct(
        public string $kind,
        public string $text = '',
        public ?Uuid $plantId = null,
        public ?Choices $choices = null,
        public ?PlantingProposal $plantingProposal = null,
        public ?SpaceProposal $spaceProposal = null,
        public bool $answered = false,
    ) {
    }

    public static function user(string $text): self
    {
        return new self(self::USER, $text);
    }

    public static function assistant(string $text): self
    {
        return new self(self::ASSISTANT, $text);
    }

    public static function toolCall(string $summary): self
    {
        return new self(self::TOOL_CALL, $summary);
    }

    public static function draftCard(Uuid $plantId): self
    {
        return new self(self::DRAFT_CARD, plantId: $plantId);
    }

    public static function choices(Choices $choices): self
    {
        return new self(self::CHOICES, choices: $choices);
    }

    public static function plantingCard(PlantingProposal $proposal): self
    {
        return new self(self::PLANTING_CARD, plantingProposal: $proposal);
    }

    public static function spaceCard(SpaceProposal $proposal): self
    {
        return new self(self::SPACE_CARD, spaceProposal: $proposal);
    }

    /**
     * The same choices, once the user answered them.
     */
    public function answer(): self
    {
        return new self($this->kind, $this->text, $this->plantId, $this->choices, $this->plantingProposal, $this->spaceProposal, true);
    }
}
