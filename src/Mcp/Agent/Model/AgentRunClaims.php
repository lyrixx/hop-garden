<?php

namespace App\Mcp\Agent\Model;

/**
 * What the token of an agent run proves: who sent the message, in which garden and conversation, until when.
 */
final readonly class AgentRunClaims
{
    public function __construct(
        /**
         * The ids, as UUIDs in RFC 4122.
         */
        public string $userId,
        public string $gardenId,
        public string $conversationId,
        public \DateTimeImmutable $expiresAt,
    ) {
    }

    public function hasExpired(\DateTimeImmutable $now): bool
    {
        return $now >= $this->expiresAt;
    }
}
