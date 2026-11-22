<?php

namespace App\Chat;

use App\Chat\Model\ChatEntry;
use App\Chat\Model\ChatEvent;
use App\Chat\Model\Choices;
use App\Garden\Model\PlantingProposal;
use App\Garden\Model\SpaceProposal;

/**
 * What a successful tool call shows in the chat, whatever APP_DEMO (ToolCallDisplay governs the MCP lines only): the
 * draft card of propose_plant, the buttons of offer_choices, the cards of propose_planting and propose_space. Read
 * from the result of the tool: live by AgentRunner, again from the stored conversation by ChatHistory.
 */
final class ToolCards
{
    /**
     * @return ?ChatEntry null for a tool without card, or a result that is not one (a failure)
     */
    public static function entryOf(string $agentToolName, mixed $result): ?ChatEntry
    {
        if (!\is_array($result)) {
            return null;
        }

        return match (ToolName::toMcp($agentToolName)) {
            'propose_plant' => \is_int($result['plant_id'] ?? null) ? ChatEntry::draftCard($result['plant_id']) : null,
            'offer_choices' => null !== ($choices = Choices::fromArray($result)) ? ChatEntry::choices($choices) : null,
            'propose_planting' => null !== ($planting = PlantingProposal::fromArray($result)) ? ChatEntry::plantingCard($planting) : null,
            'propose_space' => null !== ($space = SpaceProposal::fromArray($result)) ? ChatEntry::spaceCard($space) : null,
            default => null,
        };
    }

    /**
     * The event that pushes the card live: templates/chat/stream/<event>.stream.html.twig renders the entry.
     */
    public static function eventOf(ChatEntry $entry): ChatEvent
    {
        return match ($entry->kind) {
            ChatEntry::DRAFT_CARD => ChatEvent::DraftCard,
            ChatEntry::CHOICES => ChatEvent::Choices,
            ChatEntry::PLANTING_CARD => ChatEvent::PlantingCard,
            ChatEntry::SPACE_CARD => ChatEvent::SpaceCard,
            default => throw new \LogicException(\sprintf('A "%s" entry is not a card.', $entry->kind)),
        };
    }
}
