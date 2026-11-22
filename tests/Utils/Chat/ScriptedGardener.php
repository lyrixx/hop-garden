<?php

namespace App\Tests\Utils\Chat;

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
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Plays the "hortensia" scenario of the demo in place of an LLM: what the model decides is scripted,
 * everything else (agent, MCP client, MCP server, tools) runs for real.
 *
 * It also drafts the care sheet of a planting (see App\Garden\CareSheetDraftPrompt): straight to propose_plant when
 * the user chose the species; otherwise it searches the name, then asks a question nobody answers.
 *
 * A message holding UNREACHABLE plays a provider that does not answer, one holding BROKEN a bug of the agent, one
 * holding STREAMED a provider that streams its answer in STREAMED_DELTAS, one holding NARRATED a provider that streams
 * NARRATED_DELTAS around a call of list_collection. One holding LANGUAGE answers with the section of its prompt on the
 * language of the interface (App\Chat\InterfaceLanguagePrompt).
 *
 * PLANTED plays "add a plant the user grows" as config/agent/gardener.md asks it, from the results of the tools and
 * the messages the cards send once tapped: the care sheet of the species if validated, else a search, the choice of
 * the species and a draft (scenario C); then the space of PLACES the user named if it exists (A), else a space card
 * (B), or the choice of a space when they did not say where; then the "Add to my garden" card.
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
    public const string LANGUAGE = 'which language';
    public const string LANGUAGE_SECTION = '## The language of the interface';
    public const string PLANTED = 'Je viens de planter un hortensia';
    // The places of PLANTED, and the name of their space in the garden
    public const array PLACES = ['sur la terrasse' => 'Terrace', 'sur le balcon' => 'Balcon'];
    public const string SPECIES_QUESTION = 'Quel hortensia ?';
    public const string WHERE_QUESTION = 'Où l\'avez-vous planté ?';
    public const string CHOICES_HINT = 'Touchez-en un, ou écrivez-le.';
    public const string SPACE_READY = 'Choisissez son exposition et son sol, puis "Create the space".';
    public const string PLANTING_READY = 'Un tap sur "Add to my garden" l\'ajoute.';
    public const string ADDED = 'Il est dans votre jardin.';
    public const array NARRATED_DELTAS = ['Let me look at your garden.', 'Here it is.'];

    /**
     * With the event dispatcher of the application, as the platforms of the bundle: its listeners render the prompt of
     * the gardener, a template.
     */
    public static function createPlatform(EventDispatcherInterface $eventDispatcher): Platform
    {
        return MockPlatformFactory::createPlatform(self::answer(...), eventDispatcher: $eventDispatcher);
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

        if ('user' === $last['role'] && str_contains($text, self::LANGUAGE)) {
            return new TextResult(self::languageSection($messages));
        }

        if ('user' === $last['role'] && str_contains($text, self::NARRATED)) {
            return self::stream(new TextDelta(self::NARRATED_DELTAS[0]), new ToolCallComplete([new ToolCall('call_narrated_' . bin2hex(random_bytes(4)), 'hopgarden_list_collection', [])]));
        }

        if (self::planted($messages)) {
            $next = self::addThePlant($messages, $last, $text, self::place($messages));
            if (null !== $next) {
                return $next;
            }
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
     * The next step of "add a plant the user grows", or null when the last message is not one of its own.
     *
     * @param list<mixed>  $messages
     * @param array<mixed> $last
     * @param ?string      $space    the space the user named, null when they did not say where
     */
    private static function addThePlant(array $messages, array $last, string $text, ?string $space): ?ResultInterface
    {
        $step = 'tool' === $last['role'] && 1 === preg_match('/^call_(flow-[a-z]+)_/', (string) ($last['tool_call_id'] ?? ''), $match) ? $match[1] : null;
        $result = null !== $step ? self::decoded($last) : [];

        if ('user' === $last['role']) {
            if (str_contains($text, self::PLANTED)) {
                return self::call('flow-collection', 'hopgarden_list_collection', []);
            }

            // A tap on a choice of the species
            if (1 === preg_match('/^.+ \((Hydrangea [a-z]+)\)$/', $text, $chosen)) {
                foreach (self::resultsOf($messages, 'flow-search')[0]['candidates'] ?? [] as $candidate) {
                    if (\is_array($candidate) && $chosen[1] === $candidate['scientific_name']) {
                        return self::call('flow-propose', 'hopgarden_propose_plant', array_replace(PlantToolsTest::proposal(), [
                            'common_name' => $candidate['common_name'],
                            'scientific_name' => $candidate['scientific_name'],
                            'trefle_id' => $candidate['trefle_id'],
                        ]));
                    }
                }
            }

            // The messages of the cards, once tapped
            if (str_starts_with($text, 'I validated the care sheet of ') || str_starts_with($text, 'I created the space ')) {
                return self::call('flow-spaces', 'hopgarden_list_spaces', []);
            }
            if (str_starts_with($text, 'I added ')) {
                return new TextResult(self::ADDED);
            }

            // A tap on a space, when the user did not say where
            if (null === $space && 'flow-where' === self::lastStep($messages)) {
                return self::proposeInSpace($messages, self::resultsOf($messages, 'flow-spaces')[0] ?? [], $text);
            }

            return null;
        }

        return match ($step) {
            'flow-collection' => null !== self::hydrangeaId($messages)
                ? self::call('flow-spaces', 'hopgarden_list_spaces', [])
                : self::call('flow-search', 'hopgarden_search_plant', ['query' => 'Hydrangea']),
            'flow-search' => self::call('flow-choices', 'hopgarden_offer_choices', [
                'question' => self::SPECIES_QUESTION,
                'options' => array_map(
                    static fn (array $candidate): string => \sprintf('%s (%s)', $candidate['common_name'], $candidate['scientific_name']),
                    \array_slice(\is_array($result['candidates'] ?? null) ? $result['candidates'] : [], 0, 3),
                ),
            ]),
            'flow-choices', 'flow-where' => new TextResult(self::CHOICES_HINT),
            'flow-spaces' => null !== $space ? self::proposeInSpace($messages, $result, $space) : self::call('flow-where', 'hopgarden_offer_choices', [
                'question' => self::WHERE_QUESTION,
                'options' => [...array_map(static fn (array $space): string => $space['name'], \is_array($result['spaces'] ?? null) ? $result['spaces'] : []), 'Un nouvel espace'],
            ]),
            'flow-space' => new TextResult(self::SPACE_READY),
            'flow-planting' => new TextResult(self::PLANTING_READY),
            default => null,
        };
    }

    /**
     * @param list<mixed>  $messages
     * @param array<mixed> $spaces   the result of list_spaces
     */
    private static function proposeInSpace(array $messages, array $spaces, string $name): ResultInterface
    {
        foreach (\is_array($spaces['spaces'] ?? null) ? $spaces['spaces'] : [] as $space) {
            if (\is_array($space) && $name === $space['name']) {
                $plantId = self::hydrangeaId($messages) ?? throw new \LogicException('No care sheet of a hydrangea to propose.');

                return self::call('flow-planting', 'hopgarden_propose_planting', ['plant_id' => $plantId, 'name' => 'Hortensia', 'space_id' => $space['id']]);
            }
        }

        return self::call('flow-space', 'hopgarden_propose_space', ['name' => $name]);
    }

    /**
     * The care sheet of the hydrangea: the draft proposed in this conversation, else the validated one of the collection.
     *
     * @param list<mixed> $messages
     */
    private static function hydrangeaId(array $messages): ?int
    {
        foreach (self::resultsOf($messages, 'flow-propose') as $proposed) {
            if (\is_int($proposed['plant_id'] ?? null)) {
                return $proposed['plant_id'];
            }
        }

        foreach (self::resultsOf($messages, 'flow-collection')[0]['plants'] ?? [] as $plant) {
            if (\is_array($plant) && str_starts_with((string) $plant['scientific_name'], 'Hydrangea')) {
                return $plant['id'];
            }
        }

        return null;
    }

    /**
     * @param list<mixed> $messages
     */
    private static function languageSection(array $messages): string
    {
        foreach ($messages as $message) {
            if (\is_array($message) && 'system' === $message['role'] && str_contains(self::text($message), self::LANGUAGE_SECTION)) {
                return trim(substr(self::text($message), (int) strpos(self::text($message), self::LANGUAGE_SECTION)));
            }
        }

        throw new \LogicException('The prompt has no section on the language of the interface.');
    }

    /**
     * @param list<mixed> $messages
     */
    private static function planted(array $messages): bool
    {
        return null !== self::plantedMessage($messages);
    }

    /**
     * The name of the space of the garden where the user planted it, null when they did not say where.
     *
     * @param list<mixed> $messages
     */
    private static function place(array $messages): ?string
    {
        foreach (self::PLACES as $place => $space) {
            if (str_contains(self::plantedMessage($messages) ?? '', $place)) {
                return $space;
            }
        }

        return null;
    }

    /**
     * @param list<mixed> $messages
     */
    private static function plantedMessage(array $messages): ?string
    {
        foreach ($messages as $message) {
            if (\is_array($message) && 'user' === $message['role'] && str_contains(self::text($message), self::PLANTED)) {
                return self::text($message);
            }
        }

        return null;
    }

    /**
     * The step of the last tool call of the conversation.
     *
     * @param list<mixed> $messages
     */
    private static function lastStep(array $messages): ?string
    {
        foreach (array_reverse($messages) as $message) {
            if (\is_array($message) && 'tool' === $message['role'] && 1 === preg_match('/^call_(flow-[a-z]+)_/', (string) ($message['tool_call_id'] ?? ''), $match)) {
                return $match[1];
            }
        }

        return null;
    }

    /**
     * The results of the calls of a step, the latest first.
     *
     * @param list<mixed> $messages
     *
     * @return list<array<mixed>>
     */
    private static function resultsOf(array $messages, string $step): array
    {
        $results = [];
        foreach ($messages as $message) {
            if (\is_array($message) && 'tool' === $message['role'] && str_starts_with((string) ($message['tool_call_id'] ?? ''), 'call_' . $step . '_')) {
                array_unshift($results, self::decoded($message));
            }
        }

        return $results;
    }

    /**
     * @param array<mixed> $message the result of a tool
     *
     * @return array<mixed>
     */
    private static function decoded(array $message): array
    {
        $result = json_decode(self::text($message), true);

        return \is_array($result) ? $result : [];
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
