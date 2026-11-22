<?php

namespace App\Chat\Message;

use Symfony\Component\Messenger\Attribute\AsMessage;

/**
 * Runs the chat agent on a message of a member, in a conversation of theirs in a garden, in the worker. Its answer is
 * streamed to the browser with Mercure.
 */
#[AsMessage('async')]
final readonly class RunAgent
{
    public function __construct(
        public int $userId,
        public int $gardenId,
        public string $conversationId,
        public string $text,
        /**
         * Of the user who sent it, for the answer and the cards the worker renders: see App\Locale\PreferredLocale.
         */
        public string $locale,
        /**
         * The credential of the run on the MCP server of the agent: see App\Mcp\Agent\AgentRunTokens.
         */
        public string $agentRunToken,
    ) {
    }
}
