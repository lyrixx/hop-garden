<?php

namespace App\Chat;

use App\Chat\Model\ChatEntry;
use Symfony\AI\Agent\MessageStore\MessageStoreInterface;
use Symfony\AI\Platform\Message\AssistantMessage;
use Symfony\AI\Platform\Message\ToolCallMessage;
use Symfony\AI\Platform\Message\UserMessage;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\UuidV7;

/**
 * Replays a stored conversation the way the worker streamed it.
 */
final readonly class ChatHistory
{
    public function __construct(
        #[Autowire(service: 'ai.message_store.doctrine.dbal.chat')]
        private MessageStoreInterface $messageStore,
        private ToolCallDisplay $toolCallDisplay,
    ) {
    }

    /**
     * @return list<ChatEntry>
     */
    public function entries(UuidV7 $conversationId): array
    {
        $entries = [];

        foreach ($this->messageStore->load($conversationId)->getMessages() as $message) {
            if ($message instanceof UserMessage) {
                $entries[] = ChatEntry::user($message->asText() ?? '');
            } elseif ($message instanceof AssistantMessage) {
                $text = trim($message->asText() ?? '');
                if ('' !== $text) {
                    $entries[] = ChatEntry::assistant($text);
                }
                if ($this->toolCallDisplay->isShown()) {
                    foreach ($message->getToolCalls() as $toolCall) {
                        $entries[] = ChatEntry::toolCall(ToolCallSummary::of($toolCall->getName(), $toolCall->getArguments()));
                    }
                }
            } elseif ($message instanceof ToolCallMessage && 'propose_plant' === ToolName::toMcp($message->getToolCall()->getName())) {
                $plantId = $this->proposedPlantId($message->asText() ?? '');
                if (null !== $plantId) {
                    $entries[] = ChatEntry::draftCard($plantId);
                }
            }
        }

        return $entries;
    }

    private function proposedPlantId(string $toolResult): ?int
    {
        $result = json_decode($toolResult, true);

        return \is_array($result) && \is_int($result['plant_id'] ?? null) ? $result['plant_id'] : null;
    }
}
