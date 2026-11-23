<?php

namespace App\Tests\Unit\Chat;

use App\Chat\ChatSession;
use App\Factory\GardenFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class ChatSessionTest extends TestCase
{
    public function testOneConversationPerSessionAndGarden(): void
    {
        [$chatSession, $session] = self::chatSession();
        $garden = GardenFactory::createOne();

        $conversationId = $chatSession->getConversationId($garden);

        self::assertTrue($conversationId->equals($chatSession->getConversationId($garden)));
        self::assertSame($conversationId->toRfc4122(), $session->get('chat_conversation_id.' . $garden->getId()));
        self::assertFalse($conversationId->equals($chatSession->getConversationId(GardenFactory::createOne())), 'Another garden, another conversation.');
    }

    public function testANewConversation(): void
    {
        [$chatSession] = self::chatSession();
        $garden = GardenFactory::createOne();
        $first = $chatSession->getConversationId($garden);

        $second = $chatSession->startNewConversation($garden);

        self::assertFalse($first->equals($second));
        self::assertTrue($second->equals($chatSession->getConversationId($garden)));
    }

    public function testAnInvalidIdInTheSessionStartsANewConversation(): void
    {
        [$chatSession, $session] = self::chatSession();
        $garden = GardenFactory::createOne();
        $session->set('chat_conversation_id.' . $garden->getId(), '../../etc/passwd');

        $conversationId = $chatSession->getConversationId($garden);

        self::assertSame($conversationId->toRfc4122(), $session->get('chat_conversation_id.' . $garden->getId()));
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
