<?php

namespace App\Chat\Message;

use Symfony\Component\Messenger\Attribute\AsMessage;

/**
 * Runs the chat agent on a user message, in the worker. Its answer is streamed to the browser with Mercure.
 */
#[AsMessage('async')]
final readonly class RunAgent
{
    public function __construct(
        public string $conversationId,
        public string $text,
    ) {
    }
}
