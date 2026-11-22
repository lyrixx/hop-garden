<?php

namespace App\Chat\Model;

/**
 * A line of the chat history, as the template shows it.
 */
final readonly class ChatEntry
{
    public const string USER = 'user';
    public const string ASSISTANT = 'assistant';
    public const string TOOL_CALL = 'tool_call';
    public const string DRAFT_CARD = 'draft_card';

    private function __construct(
        public string $kind,
        public string $text = '',
        public ?int $plantId = null,
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

    public static function draftCard(int $plantId): self
    {
        return new self(self::DRAFT_CARD, plantId: $plantId);
    }
}
