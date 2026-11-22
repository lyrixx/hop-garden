<?php

namespace App\Tests\Unit\Chat;

use App\Chat\ChatSession;
use App\Factory\GardenFactory;
use App\Garden\Entity\Garden;
use App\Tests\Utils\Factory\EntityId;
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
        $garden = self::garden(7);

        $conversationId = $chatSession->getConversationId($garden);

        self::assertTrue($conversationId->equals($chatSession->getConversationId($garden)));
        self::assertSame($conversationId->toRfc4122(), $session->get('chat_conversation_id.7'));
        self::assertFalse($conversationId->equals($chatSession->getConversationId(self::garden(8))), 'Another garden, another conversation.');
    }

    public function testANewConversation(): void
    {
        [$chatSession] = self::chatSession();
        $garden = self::garden(7);
        $first = $chatSession->getConversationId($garden);

        $second = $chatSession->startNewConversation($garden);

        self::assertFalse($first->equals($second));
        self::assertTrue($second->equals($chatSession->getConversationId($garden)));
    }

    public function testAnInvalidIdInTheSessionStartsANewConversation(): void
    {
        [$chatSession, $session] = self::chatSession();
        $session->set('chat_conversation_id.7', '../../etc/passwd');

        $conversationId = $chatSession->getConversationId(self::garden(7));

        self::assertSame($conversationId->toRfc4122(), $session->get('chat_conversation_id.7'));
    }

    private static function garden(int $id): Garden
    {
        return EntityId::assign(GardenFactory::createOne(), $id);
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
