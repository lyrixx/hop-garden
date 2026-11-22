<?php

namespace App\Chat;

use App\Garden\Entity\Garden;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Uid\UuidV7;

/**
 * One conversation per browser session and per garden: the chat of a garden speaks of that garden only. Whose it is
 * lives in App\Chat\Entity\Conversation, recorded with its first message (ChatController::send()).
 */
final readonly class ChatSession
{
    private const string KEY = 'chat_conversation_id';

    public function __construct(
        private RequestStack $requestStack,
    ) {
    }

    public function getConversationId(Garden $garden): UuidV7
    {
        $id = $this->requestStack->getSession()->get(self::key($garden));
        if (\is_string($id) && UuidV7::isValid($id)) {
            return UuidV7::fromString($id);
        }

        return $this->startNewConversation($garden);
    }

    public function startNewConversation(Garden $garden): UuidV7
    {
        $id = new UuidV7();
        $this->requestStack->getSession()->set(self::key($garden), $id->toRfc4122());

        return $id;
    }

    private static function key(Garden $garden): string
    {
        return self::KEY . '.' . $garden->getId()->toRfc4122();
    }
}
