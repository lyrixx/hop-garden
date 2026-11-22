<?php

namespace App\Tests\Integration\Chat;

use App\Chat\ChatHistory;
use App\Chat\Model\ChatEntry;
use App\Chat\RunAgentHandler;
use App\Factory\PlantFactory;
use App\Factory\SpaceFactory;
use App\Garden\Entity\Space;
use App\Garden\Model\Soil;
use App\Plant\Model\Exposure;
use App\Tests\Utils\Chat\AgentRunsTrait;
use App\Tests\Utils\Chat\ScriptedGardener;
use App\Tests\Utils\Env\EnvVarsTrait;
use App\Tests\Utils\Mercure\RecordingHub;
use App\Tests\Utils\Mercure\TurboStream;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\UuidV7;

/**
 * The choices and the cards of the agent are their own events, whatever APP_DEMO: pushed live, and replayed.
 */
final class AgentFlowTest extends KernelTestCase
{
    use AgentRunsTrait;
    use EnvVarsTrait;

    #[TestWith(['1'])]
    #[TestWith(['0'])]
    public function testASpaceCardThenAPlantingCard(string $demo): void
    {
        $this->setEnvVar('APP_DEMO', $demo);
        self::bootKernel();
        $plant = PlantFactory::new()->validated()->create();
        $conversationId = (new UuidV7())->toRfc4122();
        $handler = self::getContainer()->get(RunAgentHandler::class);

        $handler($this->runAgentMessage($conversationId, ScriptedGardener::PLANTED . ' sur le balcon', 'en'));
        // What the space card does once tapped
        SpaceFactory::createOne(['name' => 'Balcon', 'exposure' => Exposure::Sun, 'soil' => Soil::Loamy, 'createdAt' => new \DateTimeImmutable('2026-11-22')]);
        $handler($this->runAgentMessage($conversationId, 'I created the space Balcon.', 'en'));

        self::assertSame(['chat-card chat-proposal is-arriving', 'chat-card chat-proposal is-arriving'], $this->appended($conversationId));

        $history = self::getContainer()->get(ChatHistory::class)->entries(UuidV7::fromString($conversationId));
        $cards = array_values(array_filter($history, static fn (ChatEntry $entry): bool => \in_array($entry->kind, [ChatEntry::SPACE_CARD, ChatEntry::PLANTING_CARD], true)));
        self::assertSame([ChatEntry::SPACE_CARD, ChatEntry::PLANTING_CARD], array_map(static fn (ChatEntry $entry): string => $entry->kind, $cards));
        self::assertSame('Balcon', $cards[0]->spaceProposal?->name);
        self::assertSame($plant->getId(), $cards[1]->plantingProposal?->plantId);
        self::assertSame('1' === $demo, \in_array(ChatEntry::TOOL_CALL, array_map(static fn (ChatEntry $entry): string => $entry->kind, $history), true), 'The MCP lines in demo mode only.');
    }

    /**
     * In demo mode: the search of the species reaches Trefle otherwise.
     */
    public function testChoicesThenTheDraftCard(): void
    {
        self::bootKernel();
        $conversationId = (new UuidV7())->toRfc4122();
        $handler = self::getContainer()->get(RunAgentHandler::class);

        $handler($this->runAgentMessage($conversationId, ScriptedGardener::PLANTED . ' sur la terrasse', 'en'));
        $choices = $this->choices($conversationId);
        self::assertCount(1, $choices);
        self::assertFalse($choices[0]->answered, 'The last question stays on.');
        self::assertSame(ScriptedGardener::SPECIES_QUESTION, $choices[0]->choices?->question);

        $handler($this->runAgentMessage($conversationId, (string) $choices[0]->choices->options[0], 'en'));

        self::assertSame(['chat-choices is-arriving', 'chat-card is-arriving'], $this->appended($conversationId));
        self::assertTrue($this->choices($conversationId)[0]->answered, 'Answered by the next message.');
    }

    /**
     * The classes of what the worker appended to the chat, the bubbles aside.
     *
     * @return list<string>
     */
    private function appended(string $conversationId): array
    {
        $appended = [];
        foreach (RecordingHub::published($this->conversationTopic($conversationId)) as $update) {
            foreach (TurboStream::parseAll($update) as $stream) {
                if ('append' === $stream->action && 'chat-log' === $stream->target) {
                    $appended[] = (string) $stream->element()->attr('class');
                }
            }
        }

        return array_values(array_filter($appended, static fn (string $class): bool => !str_contains($class, 'bubble')));
    }

    /**
     * @return list<ChatEntry>
     */
    private function choices(string $conversationId): array
    {
        $entries = self::getContainer()->get(ChatHistory::class)->entries(UuidV7::fromString($conversationId));

        return array_values(array_filter($entries, static fn (ChatEntry $entry): bool => ChatEntry::CHOICES === $entry->kind));
    }
}
