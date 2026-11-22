<?php

namespace App\Garden\Message;

use Symfony\Component\Messenger\Attribute\AsMessage;

/**
 * Has the gardener agent draft the care sheet of a planting, in the worker, in the conversation of this attempt.
 */
#[AsMessage('async')]
final readonly class DraftCareSheet
{
    public function __construct(
        public int $plantingId,
        public string $conversationId,
    ) {
    }
}
