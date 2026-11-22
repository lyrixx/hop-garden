<?php

namespace App\Tests\Integration\Chat;

use App\Chat\RunAgentHandler;
use App\Tests\Utils\Chat\AgentRunsTrait;
use App\Tests\Utils\Chat\ScriptedGardener;
use App\Tests\Utils\Garden\TwoGardens;
use App\Tests\Utils\Mercure\RecordingHub;
use Symfony\AI\Platform\Message\ToolCallMessage;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\UuidV7;

/**
 * The agent of the chat sees the garden of the member who wrote, through the MCP tools of their run: Bob the garden he
 * shares with Alice, then Carol her own, one after the other in the same worker, with the same MCP client.
 */
final class ChatOfAGardenTest extends KernelTestCase
{
    use AgentRunsTrait;

    public function testTheToolsOfARunSeeTheGardenOfItsSenderOnly(): void
    {
        self::bootKernel();
        $gardens = TwoGardens::create(self::getContainer());
        RecordingHub::reset();
        $handler = self::getContainer()->get(RunAgentHandler::class);
        $bobsConversation = new UuidV7();
        $carolsConversation = new UuidV7();

        $handler($this->runAgentMessage($bobsConversation, ScriptedGardener::NARRATED, 'fr', $gardens->bob, $gardens->allotment));
        $handler($this->runAgentMessage($carolsConversation, ScriptedGardener::NARRATED, 'en', $gardens->carol, $gardens->balcony));

        self::assertSame(['Climbing rose'], $this->collectionSeenIn($bobsConversation));
        self::assertSame(['Lavender of Carol'], $this->collectionSeenIn($carolsConversation), 'Hers only.');

        self::assertNotSame([], RecordingHub::published($this->conversationTopic($carolsConversation, $gardens->carol)));
        self::assertSame([$this->conversationTopic($bobsConversation, $gardens->bob), $this->conversationTopic($carolsConversation, $gardens->carol)], RecordingHub::topics(), 'Each run on the topic of its sender.');
        foreach (RecordingHub::topics() as $topic) {
            foreach (RecordingHub::updates($topic) as $update) {
                self::assertTrue($update->isPrivate(), $topic);
            }
        }
    }

    /**
     * @return list<string> what list_collection gave the agent, as stored in the conversation
     */
    private function collectionSeenIn(UuidV7 $conversationId): array
    {
        foreach (self::getContainer()->get('ai.message_store.doctrine.dbal.chat')->load($conversationId)->getMessages() as $message) {
            if ($message instanceof ToolCallMessage && 'hopgarden_list_collection' === $message->getToolCall()->getName()) {
                $result = json_decode($message->asText() ?? '', true, flags: JSON_THROW_ON_ERROR);
                self::assertIsArray($result);
                self::assertIsArray($result['plants'] ?? null);

                return array_column($result['plants'], 'common_name');
            }
        }

        self::fail('The agent did not call list_collection.');
    }
}
