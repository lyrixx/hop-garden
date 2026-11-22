<?php

namespace App\Tests\Integration\Chat;

use App\Chat\ChatHistory;
use App\Chat\Message\RunAgent;
use App\Chat\Model\ChatEntry;
use App\Chat\RunAgentHandler;
use App\Plant\Model\PlantStatus;
use App\Plant\PlantRepository;
use App\Tests\Chat\ScriptedGardener;
use App\Tests\Env\EnvVarsTrait;
use App\Tests\Mercure\ChatStreams;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\UuidV7;

final class RunAgentHandlerTest extends KernelTestCase
{
    use EnvVarsTrait;

    public function testTheAgentGoesThroughMcpAndOnlyProposesADraft(): void
    {
        self::bootKernel();
        $conversationId = new UuidV7();
        $handler = self::getContainer()->get(RunAgentHandler::class);

        $handler(new RunAgent($conversationId->toRfc4122(), 'hortensia'));

        $events = $this->events($conversationId);
        self::assertSame(['start', 'tool_call', 'answer', 'done'], array_values(array_unique(array_column($events, 'type'))));
        self::assertSame('search_plant(query: "hortensia")', $events[1]['summary']);
        self::assertSame(['type' => 'answer', 'bubble' => 'new', 'html' => '<p>' . ScriptedGardener::QUESTION . '</p>'], $events[2], 'Rendered on the server, as the history.');

        $handler(new RunAgent($conversationId->toRfc4122(), 'paniculata'));

        $events = \array_slice($this->events($conversationId), \count($events));
        $toolCalls = array_column(array_filter($events, static fn (array $event): bool => 'tool_call' === $event['type']), 'summary');
        self::assertCount(1, $toolCalls);
        self::assertStringStartsWith('propose_plant(', array_values($toolCalls)[0]);

        $cards = array_values(array_filter($events, static fn (array $event): bool => 'draft_card' === $event['type']));
        self::assertCount(1, $cards);
        $plant = self::getContainer()->get(PlantRepository::class)->get($cards[0]['plant_id']);
        self::assertSame('Hydrangea paniculata', $plant->getScientificName());
        self::assertSame(PlantStatus::Draft, $plant->getStatus(), 'The agent never validates a plant.');
        self::assertSame('done', $events[\count($events) - 1]['type']);

        $history = self::getContainer()->get(ChatHistory::class)->entries($conversationId);
        self::assertSame(
            [ChatEntry::USER, ChatEntry::TOOL_CALL, ChatEntry::ASSISTANT, ChatEntry::USER, ChatEntry::TOOL_CALL, ChatEntry::DRAFT_CARD, ChatEntry::ASSISTANT],
            array_map(static fn (ChatEntry $entry): string => $entry->kind, $history),
        );
        self::assertSame($plant->getId(), $history[5]->plantId);
    }

    public function testAStreamedAnswerIsPushedInBatches(): void
    {
        self::bootKernel();
        $conversationId = new UuidV7();

        self::getContainer()->get(RunAgentHandler::class)(new RunAgent($conversationId->toRfc4122(), ScriptedGardener::STREAMED));

        self::assertSame(
            [
                ['type' => 'start'],
                // 24 characters at least, or a new line: one Mercure update per token would be wasteful. Each one holds
                // the whole answer so far, rendered as the history renders it
                ['type' => 'answer', 'bubble' => 'new', 'html' => '<p>Prune it in March, above a bud.</p>'],
                ['type' => 'answer', 'bubble' => 'same', 'html' => '<p>Prune it in March, above a bud.</p>'],
                ['type' => 'answer', 'bubble' => 'same', 'html' => '<p>Prune it in March, above a bud.</p><p>Then mulch.</p>'],
                ['type' => 'done'],
            ],
            $this->events($conversationId),
        );
        self::assertSame(implode('', ScriptedGardener::STREAMED_DELTAS), self::getContainer()->get(ChatHistory::class)->entries($conversationId)[1]->text ?? null, 'Stored whole.');
    }

    public function testWhatTheAgentSaysAroundAToolCall(): void
    {
        self::bootKernel();
        $conversationId = new UuidV7();

        self::getContainer()->get(RunAgentHandler::class)(new RunAgent($conversationId->toRfc4122(), ScriptedGardener::NARRATED));

        self::assertSame(
            [
                ['type' => 'start'],
                ['type' => 'answer', 'bubble' => 'new', 'html' => '<p>' . ScriptedGardener::NARRATED_DELTAS[0] . '</p>'],
                ['type' => 'tool_call', 'summary' => 'list_collection()'],
                // The tool call line closes the bubble
                ['type' => 'answer', 'bubble' => 'new', 'html' => '<p>' . ScriptedGardener::NARRATED_DELTAS[1] . '</p>'],
                ['type' => 'done'],
            ],
            $this->events($conversationId),
        );
    }

    public function testOutOfTheDemoModeNoToolCallIsPushed(): void
    {
        $this->setEnvVar('APP_DEMO', '0');
        self::bootKernel();
        $conversationId = new UuidV7();
        $handler = self::getContainer()->get(RunAgentHandler::class);

        $handler(new RunAgent($conversationId->toRfc4122(), ScriptedGardener::NARRATED));

        $events = $this->events($conversationId);
        self::assertSame(
            [
                ['type' => 'start'],
                ['type' => 'answer', 'bubble' => 'new', 'html' => '<p>' . ScriptedGardener::NARRATED_DELTAS[0] . '</p>'],
                // No tool call line closes the bubble on the page: a blank line keeps the two answers apart
                ['type' => 'answer', 'bubble' => 'same', 'html' => '<p>' . ScriptedGardener::NARRATED_DELTAS[0] . '</p><p>' . ScriptedGardener::NARRATED_DELTAS[1] . '</p>'],
                ['type' => 'done'],
            ],
            $events,
        );

        $handler(new RunAgent($conversationId->toRfc4122(), 'paniculata'));

        $events = \array_slice($this->events($conversationId), \count($events));
        self::assertSame(['start', 'draft_card', 'answer', 'done'], array_values(array_unique(array_column($events, 'type'))), 'The draft card still comes.');
        self::assertSame(
            [ChatEntry::USER, ChatEntry::ASSISTANT, ChatEntry::ASSISTANT, ChatEntry::USER, ChatEntry::DRAFT_CARD, ChatEntry::ASSISTANT],
            array_map(static fn (ChatEntry $entry): string => $entry->kind, self::getContainer()->get(ChatHistory::class)->entries($conversationId)),
            'Nor replayed.',
        );
    }

    public function testAProviderThatDoesNotAnswerEndsTheRunWithAnError(): void
    {
        self::bootKernel();
        $conversationId = new UuidV7();

        // Handled: the worker goes on with the next message
        self::getContainer()->get(RunAgentHandler::class)(new RunAgent($conversationId->toRfc4122(), 'hortensia, ' . ScriptedGardener::UNREACHABLE));

        self::assertSame(
            [['type' => 'start'], ['type' => 'error', 'message' => 'The assistant could not answer: The model provider does not answer.'], ['type' => 'done']],
            $this->events($conversationId),
        );
    }

    public function testABugStillTellsThePageToStopWaiting(): void
    {
        self::bootKernel();
        $conversationId = new UuidV7();

        try {
            self::getContainer()->get(RunAgentHandler::class)(new RunAgent($conversationId->toRfc4122(), ScriptedGardener::BROKEN));
            self::fail('A bug is not hidden.');
        } catch (\LogicException $e) {
            self::assertSame('The agent is broken.', $e->getMessage());
        }

        self::assertSame(
            [['type' => 'start'], ['type' => 'error', 'message' => 'The assistant could not answer, see the logs of the worker.'], ['type' => 'done']],
            $this->events($conversationId),
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function events(UuidV7 $conversationId): array
    {
        return ChatStreams::of($conversationId->toRfc4122());
    }
}
