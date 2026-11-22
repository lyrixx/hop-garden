<?php

namespace App\Garden\Message;

use Symfony\Component\Messenger\Attribute\AsMessage;

/**
 * Has the gardener agent draft the care sheet of a planting, in the worker, in the conversation of this attempt: for the
 * member who added it, in its garden.
 */
#[AsMessage('async')]
final readonly class DraftCareSheet
{
    public function __construct(
        /**
         * The ids, as UUIDs in RFC 4122.
         */
        public string $plantingId,
        public string $userId,
        public string $gardenId,
        public string $conversationId,
        /**
         * Of the user who added the plant, for the care sheet the agent drafts: see App\Locale\PreferredLocale.
         */
        public string $locale,
        /**
         * The credential of the run on the MCP server of the agent: see App\Mcp\Agent\AgentRunTokens.
         */
        public string $agentRunToken,
    ) {
    }
}
