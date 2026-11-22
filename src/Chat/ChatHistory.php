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
        // Not #[Target('chat')]: symfony/ai-bundle 1.0.0-BETA1 aliases that target to a missing service,
        // "ai.message_store.doctrine.chat" (without ".dbal")
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
            } elseif ($message instanceof ToolCallMessage) {
                $card = ToolCards::entryOf($message->getToolCall()->getName(), json_decode($message->asText() ?? '', true));
                if (null !== $card) {
                    $entries[] = $card;
                }
            }
        }

        return self::answerChoices($entries);
    }

    /**
     * Only the last question has its buttons on, until the user answers it, by a tap or by typing.
     *
     * @param list<ChatEntry> $entries
     *
     * @return list<ChatEntry>
     */
    private static function answerChoices(array $entries): array
    {
        // From the end: a question is off once a message of the user, or a later question, follows it
        $followed = false;
        $reversed = [];
        foreach (array_reverse($entries) as $entry) {
            $reversed[] = ChatEntry::CHOICES === $entry->kind && $followed ? $entry->answer() : $entry;
            $followed = $followed || \in_array($entry->kind, [ChatEntry::USER, ChatEntry::CHOICES], true);
        }

        return array_reverse($reversed);
    }
}
