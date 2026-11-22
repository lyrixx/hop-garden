<?php

namespace App\Tests\Chat;

use App\Tests\Integration\Mcp\PlantToolsTest;
use Symfony\AI\Platform\Exception\RuntimeException;
use Symfony\AI\Platform\Platform;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\Result\Stream\Delta\DeltaInterface;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\AI\Platform\Result\Stream\Delta\ToolCallComplete;
use Symfony\AI\Platform\Result\StreamResult;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Result\ToolCallResult;
use Symfony\AI\Platform\Test\MockPlatformFactory;

/**
 * Plays the "hortensia" scenario of the demo in place of an LLM: what the model decides is scripted,
 * everything else (agent, MCP client, MCP server, tools) runs for real.
 *
 * It also drafts the care sheet of a planting (see App\Garden\CareSheetDraftPrompt): straight to propose_plant when
 * the user chose the species; otherwise it searches the name, then asks a question nobody answers.
 *
 * A message holding UNREACHABLE plays a provider that does not answer, one holding BROKEN a bug of the agent, one
 * holding STREAMED a provider that streams its answer in STREAMED_DELTAS, one holding NARRATED a provider that streams
 * NARRATED_DELTAS around a call of list_collection.
 */
final class ScriptedGardener
{
    public const string QUESTION = 'Which one do you have? 1. Bigleaf hydrangea 2. Panicled hydrangea 3. Smooth hydrangea';
    public const string DRAFT_READY = 'The draft is ready for review: please double-check the cutting months.';
    public const string UNREACHABLE = 'unreachable model';
    public const string BROKEN = 'broken agent';
    public const string STREAMED = 'streamed answer';
    public const array STREAMED_DELTAS = ['Prune it ', 'in March, ', 'above a bud.', "\n", 'Then ', 'mulch.'];
    public const string NARRATED = 'narrated answer';
    public const array NARRATED_DELTAS = ['Let me look at your garden.', 'Here it is.'];

    public static function createPlatform(): Platform
    {
        return MockPlatformFactory::createPlatform(self::answer(...));
    }

    /**
     * @param array<mixed>|string|object $payload
     * @param array<string, mixed>       $options
     */
    private static function answer(mixed $model, array|string|object $payload, array $options): ResultInterface
    {
        $messages = \is_array($payload) && \is_array($payload['messages'] ?? null) ? $payload['messages'] : [];
        $last = end($messages);
        if (!\is_array($last)) {
            throw new \LogicException('The scripted gardener expects a conversation.');
        }

        $text = self::text($last);
        $chosenSpecies = 'user' === $last['role'] && 1 === preg_match('/I chose its species: (.+) \(Trefle id (\d+)\)/', $text, $species);

        if ('user' === $last['role'] && str_contains($text, self::UNREACHABLE)) {
            throw new RuntimeException('The model provider does not answer.');
        }

        if ('user' === $last['role'] && str_contains($text, self::BROKEN)) {
            throw new \LogicException('The agent is broken.');
        }

        if ('user' === $last['role'] && str_contains($text, self::STREAMED)) {
            return self::stream(...array_map(static fn (string $delta): TextDelta => new TextDelta($delta), self::STREAMED_DELTAS));
        }

        if ('user' === $last['role'] && str_contains($text, self::NARRATED)) {
            return self::stream(new TextDelta(self::NARRATED_DELTAS[0]), new ToolCallComplete([new ToolCall('call_narrated_' . bin2hex(random_bytes(4)), 'hopgarden_list_collection', [])]));
        }

        return match (true) {
            $chosenSpecies => self::call('propose', 'hopgarden_propose_plant', array_replace(PlantToolsTest::proposal(), ['scientific_name' => $species[1], 'trefle_id' => (int) $species[2]])),
            'user' === $last['role'] && str_contains($text, 'paniculata') => self::call('propose', 'hopgarden_propose_plant', PlantToolsTest::proposal()),
            'user' === $last['role'] => self::call('search', 'hopgarden_search_plant', ['query' => 1 === preg_match('/which I call "(.+?)"/', $text, $name) ? $name[1] : $text]),
            str_starts_with((string) ($last['tool_call_id'] ?? ''), 'call_search') => new TextResult(self::QUESTION),
            str_starts_with((string) ($last['tool_call_id'] ?? ''), 'call_narrated') => self::stream(new TextDelta(self::NARRATED_DELTAS[1])),
            default => new TextResult(self::DRAFT_READY),
        };
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private static function call(string $step, string $tool, array $arguments): ToolCallResult
    {
        return new ToolCallResult([new ToolCall('call_' . $step . '_' . bin2hex(random_bytes(4)), $tool, $arguments)]);
    }

    private static function stream(DeltaInterface ...$deltas): StreamResult
    {
        return new StreamResult((static function () use ($deltas): \Generator {
            yield from $deltas;
        })());
    }

    /**
     * @param array<mixed> $message
     */
    private static function text(array $message): string
    {
        $content = $message['content'] ?? '';
        if (\is_string($content)) {
            return $content;
        }

        $text = '';
        foreach (\is_array($content) ? $content : [] as $part) {
            $text .= \is_array($part) && \is_string($part['text'] ?? null) ? $part['text'] : '';
        }

        return $text;
    }
}
