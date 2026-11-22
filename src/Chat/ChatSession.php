<?php

namespace App\Chat;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Uid\UuidV7;

/**
 * One conversation per browser session.
 */
final readonly class ChatSession
{
    private const string KEY = 'chat_conversation_id';

    public function __construct(
        private RequestStack $requestStack,
    ) {
    }

    public function getConversationId(): UuidV7
    {
        $session = $this->requestStack->getSession();

        $id = $session->get(self::KEY);
        if (\is_string($id) && UuidV7::isValid($id)) {
            return UuidV7::fromString($id);
        }

        return $this->startNewConversation();
    }

    public function startNewConversation(): UuidV7
    {
        $id = new UuidV7();
        $this->requestStack->getSession()->set(self::KEY, $id->toRfc4122());

        return $id;
    }
}
