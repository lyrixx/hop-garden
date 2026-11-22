<?php

namespace App\Tests\Integration\Chat;

use App\Chat\ChatHistory;
use App\Chat\Model\ChatEntry;
use App\Chat\RunAgentHandler;
use App\Factory\PlantingFactory;
use App\Garden\DraftCareSheetHandler;
use App\Tests\Utils\Chat\AgentRunsTrait;
use App\Tests\Utils\Chat\ScriptedGardener;
use App\Tests\Utils\Mercure\RecordingHub;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\UuidV7;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The worker runs each message in the locale it carries: the agent gets it at the end of its prompt, and the cards and
 * errors it pushes are rendered in it. The preferred locale is left unset: only the message tells.
 */
final class InterfaceLanguageTest extends KernelTestCase
{
    use AgentRunsTrait;

    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function testTheAgentGetsTheLanguageOfTheInterface(): void
    {
        $conversationId = $this->send(ScriptedGardener::LANGUAGE, 'fr');

        $section = $this->answer($conversationId);
        self::assertStringContainsString('The interface of Hop Garden is in French (fr). Reply in French by default', $section);
        self::assertStringContainsString('write in French what shows in the interface: the common name', $section);
        self::assertStringContainsString('The buttons of the cards read "Valider", "Créer l’espace", "Ajouter à mon jardin".', $section, 'As the cards show them.');

        self::assertSame(
            [ChatEntry::USER, ChatEntry::ASSISTANT],
            array_map(static fn (ChatEntry $entry): string => $entry->kind, self::getContainer()->get(ChatHistory::class)->entries($conversationId)),
            'Given at each call, never stored with the conversation.',
        );
    }

    public function testInEnglish(): void
    {
        $section = $this->answer($this->send(ScriptedGardener::LANGUAGE, 'en'));

        self::assertStringContainsString('The interface of Hop Garden is in English (en).', $section);
        self::assertStringContainsString('The buttons of the cards read "Validate", "Create the space", "Add to my garden".', $section);
    }

    public function testTheCardsAndTheErrorsOfTheWorkerAreInTheLanguageOfTheMessage(): void
    {
        $conversationId = $this->send('paniculata', 'fr');

        $cards = array_values(array_filter($this->conversationStreams($conversationId->toRfc4122()), static fn (array $event): bool => 'draft_card' === $event['type']));
        self::assertCount(1, $cards, 'After the MCP call of propose_plant.');
        self::assertStringContainsString('Chargement du brouillon…', $this->published($conversationId));

        $conversationId = $this->send(ScriptedGardener::UNREACHABLE, 'fr');

        self::assertSame(
            ['type' => 'error', 'message' => "L’assistant n’a pas pu répondre\u{a0}: The model provider does not answer."],
            $this->conversationStreams($conversationId->toRfc4122())[1],
            'The reason is the one of the provider, as is.',
        );
    }

    public function testTheCareSheetDraftedForAPlantingIsInTheLanguageOfTheMessage(): void
    {
        $planting = PlantingFactory::new()->drafting()->create(['name' => 'Hortensia du portail', 'trefleId' => 191052, 'scientificName' => 'Hydrangea paniculata']);

        self::getContainer()->get(DraftCareSheetHandler::class)($this->draftCareSheetMessage($planting, 'fr'));

        $drafted = array_values(array_filter($this->draftingStreams($planting), static fn (array $event): bool => 'care_sheet_drafted' === $event['type']));
        self::assertStringContainsString('Le brouillon de sa fiche de culture attend votre relecture', (string) $drafted[0]['html']);
        self::assertStringContainsString('Relire et valider la fiche de culture', (string) $drafted[1]['html']);
    }

    public function testTheWorkerIsBackToTheDefaultLocaleAfterAMessage(): void
    {
        $this->send('paniculata', 'fr');

        self::assertSame('en', self::getContainer()->get(TranslatorInterface::class)->getLocale(), 'The next message starts afresh.');
    }

    private function send(string $text, string $locale): UuidV7
    {
        $conversationId = new UuidV7();
        self::getContainer()->get(RunAgentHandler::class)($this->runAgentMessage($conversationId->toRfc4122(), $text, $locale));

        return $conversationId;
    }

    /**
     * The text of the answer, as the page shows it.
     */
    private function answer(UuidV7 $conversationId): string
    {
        $answers = array_values(array_filter($this->conversationStreams($conversationId->toRfc4122()), static fn (array $event): bool => 'answer' === $event['type']));

        return (string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags((string) end($answers)['html']), ENT_QUOTES | ENT_HTML5));
    }

    private function published(UuidV7 $conversationId): string
    {
        return implode("\n", RecordingHub::published($this->conversationTopic($conversationId->toRfc4122())));
    }
}
