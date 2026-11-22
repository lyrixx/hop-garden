<?php

namespace App\Tests\Unit\Chat;

use App\Chat\ChatSession;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class ChatSessionTest extends TestCase
{
    public function testOneConversationPerSession(): void
    {
        [$chatSession, $session] = self::chatSession();

        $conversationId = $chatSession->getConversationId();

        self::assertTrue($conversationId->equals($chatSession->getConversationId()));
        self::assertSame($conversationId->toRfc4122(), $session->get('chat_conversation_id'));
    }

    public function testANewConversation(): void
    {
        [$chatSession] = self::chatSession();
        $first = $chatSession->getConversationId();

        $second = $chatSession->startNewConversation();

        self::assertFalse($first->equals($second));
        self::assertTrue($second->equals($chatSession->getConversationId()));
    }

    public function testAnInvalidIdInTheSessionStartsANewConversation(): void
    {
        [$chatSession, $session] = self::chatSession();
        $session->set('chat_conversation_id', '../../etc/passwd');

        $conversationId = $chatSession->getConversationId();

        self::assertSame($conversationId->toRfc4122(), $session->get('chat_conversation_id'));
    }

    /**
     * @return array{ChatSession, Session}
     */
    private static function chatSession(): array
    {
        $session = new Session(new MockArraySessionStorage());
        $request = new Request();
        $request->setSession($session);
        $requestStack = new RequestStack();
        $requestStack->push($request);

        return [new ChatSession($requestStack), $session];
    }
}
