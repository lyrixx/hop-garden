<?php

namespace App\Tests\Unit\Chat;

use App\Chat\ChatHistory;
use App\Chat\Model\ChatEntry;
use App\Chat\ToolCallDisplay;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Agent\MessageStore\InMemoryMessageStore;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Result\ToolCallResult;
use Symfony\Component\Uid\UuidV7;

final class ChatHistoryTest extends TestCase
{
    public function testAConversationIsReplayedAsTheWorkerStreamedIt(): void
    {
        [$store, $conversationId] = self::storedConversation();

        $entries = (new ChatHistory($store, new ToolCallDisplay(true)))->entries($conversationId);

        self::assertSame([
            [ChatEntry::USER, 'hortensia', null],
            [ChatEntry::TOOL_CALL, 'search_plant(query: "hortensia")', null],
            [ChatEntry::ASSISTANT, 'Which one do you have?', null],
            [ChatEntry::USER, 'paniculata', null],
            [ChatEntry::TOOL_CALL, 'propose_plant(common_name: "Panicled hydrangea")', null],
            [ChatEntry::TOOL_CALL, 'propose_plant(common_name: "Panicled hydrangea")', null],
            [ChatEntry::DRAFT_CARD, '', '0196a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b'],
        ], self::rows($entries), 'No system prompt, no blank answer, a card for a draft only.');
    }

    public function testOutOfTheDemoModeTheToolCallsAreNotReplayed(): void
    {
        [$store, $conversationId] = self::storedConversation();

        $entries = (new ChatHistory($store, new ToolCallDisplay(false)))->entries($conversationId);

        self::assertSame([
            [ChatEntry::USER, 'hortensia', null],
            [ChatEntry::ASSISTANT, 'Which one do you have?', null],
            [ChatEntry::USER, 'paniculata', null],
            [ChatEntry::DRAFT_CARD, '', '0196a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b'],
        ], self::rows($entries), 'The card of the draft stays.');
    }

    public function testANewConversationIsEmpty(): void
    {
        self::assertSame([], (new ChatHistory(new InMemoryMessageStore(), new ToolCallDisplay(true)))->entries(new UuidV7()));
    }

    /**
     * @return array{InMemoryMessageStore, UuidV7}
     */
    private static function storedConversation(): array
    {
        $search = new ToolCall('call_1', 'hopgarden_search_plant', ['query' => 'hortensia']);
        $propose = new ToolCall('call_2', 'hopgarden_propose_plant', ['common_name' => 'Panicled hydrangea']);
        $proposeAgain = new ToolCall('call_3', 'hopgarden_propose_plant', ['common_name' => 'Panicled hydrangea']);
        $conversationId = new UuidV7();
        $store = new InMemoryMessageStore();
        $store->save(MessageBag::forId(
            $conversationId,
            Message::forSystem('You are a gardener.'),
            Message::ofUser('hortensia'),
            Message::ofAssistant(new ToolCallResult([$search])),
            Message::ofToolCall($search, '{"candidates":[]}'),
            Message::ofAssistant('  '),
            Message::ofAssistant('Which one do you have?'),
            Message::ofUser('paniculata'),
            Message::ofAssistant(new ToolCallResult([$propose, $proposeAgain])),
            Message::ofToolCall($propose, '{"plant_id":"0196a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b","status":"draft"}'),
            Message::ofToolCall($proposeAgain, 'Invalid parameters for tool \'propose_plant\''),
        ));

        return [$store, $conversationId];
    }

    /**
     * @param list<ChatEntry> $entries
     *
     * @return list<array{string, string, ?int}>
     */
    private static function rows(array $entries): array
    {
        return array_map(static fn (ChatEntry $entry): array => [$entry->kind, $entry->text, $entry->plantId?->toRfc4122()], $entries);
    }
}
