<?php

namespace App\Tests\Integration\Garden;

use App\Chat\ChatHistory;
use App\Chat\ChatPublisher;
use App\Garden\DraftCareSheetHandler;
use App\Garden\Message\DraftCareSheet;
use App\Garden\Model\Planting;
use App\Garden\PlantingRepository;
use App\Tests\Chat\ScriptedGardener;
use App\Tests\Factory\PlantFactory;
use App\Tests\Mercure\ChatStreams;
use App\Tests\Mercure\RecordingHub;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\UuidV7;

/**
 * The whole run: worker handler, agent with a scripted model, MCP client, MCP server, propose_plant.
 */
final class DraftCareSheetHandlerTest extends KernelTestCase
{
    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function testTheAgentDraftsTheCareSheetOfTheSpeciesTheUserChose(): void
    {
        $planting = $this->startDrafting(new Planting('Hortensia du portail', null, null, new \DateTimeImmutable('2026-11-01'), 191052, 'Hydrangea paniculata'));

        $this->handle($planting);

        $events = $this->events($planting);
        // The page of the planting shows the draft once it is linked to it, in place
        self::assertSame(['start', 'tool_call', 'care_sheet_drafted', 'draft_card', 'answer', 'done'], array_values(array_unique(array_column($events, 'type'))));
        $drafted = array_values(array_filter($events, static fn (array $event): bool => 'care_sheet_drafted' === $event['type']));
        self::assertSame(
            [['update', 'planting-' . $planting->getId() . '-draft-status'], ['update', 'planting-' . $planting->getId() . '-species'], ['remove', 'planting-' . $planting->getId() . '-same-species'], ['remove', 'planting-' . $planting->getId() . '-chosen-species']],
            array_map(static fn (array $event): array => [$event['action'], $event['target']], $drafted),
        );
        self::assertStringContainsString('waiting for your review', (string) $drafted[0]['html']);
        self::assertStringContainsString('Hydrangea paniculata', (string) $drafted[1]['html']);
        self::assertStringNotContainsString('<form', (string) $drafted[1]['html'], 'Nothing to sign: the worker has no session.');
        self::assertStringStartsWith('propose_plant(common_name: "Panicled hydrangea", scientific_name: "Hydrangea paniculata"', $events[1]['summary']);

        $prompt = self::getContainer()->get(ChatHistory::class)->entries(UuidV7::fromString($planting->getConversationId() ?? ''))[0]->text;
        self::assertStringContainsString('I chose its species: Hydrangea paniculata (Trefle id 191052).', $prompt);
        self::assertStringContainsString('ask no question', $prompt);

        $planting = $this->reload($planting);
        $plant = $planting->getPlant() ?? self::fail('The planting is linked to the draft.');
        $cards = array_values(array_filter($events, static fn (array $event): bool => 'draft_card' === $event['type']));
        self::assertSame($cards[0]['plant_id'], $plant->getId());
        self::assertSame('Hydrangea paniculata', $plant->getScientificName());
        self::assertSame(191052, $plant->getTrefleId());
        self::assertFalse($plant->isValidated(), 'The agent never validates a plant.');
        self::assertFalse($planting->isDrafting());
        self::assertFalse($planting->hasDraftingFailed());
    }

    public function testARunWithoutDraftIsAFailureTheUserCanRetry(): void
    {
        $planting = $this->startDrafting(new Planting('The mystery shrub', null, 'Yellow leaves.', new \DateTimeImmutable('2026-11-01')));

        $this->handle($planting);

        $events = $this->events($planting);
        self::assertSame(['start', 'tool_call', 'answer', 'done', 'refresh'], array_column($events, 'type'));
        self::assertSame('search_plant(query: "The mystery shrub")', $events[1]['summary']);

        $planting = $this->reload($planting);
        self::assertNull($planting->getPlant());
        self::assertTrue($planting->hasDraftingFailed());
        self::assertFalse($planting->isDrafting());
        self::assertSame('Yellow leaves.', $planting->getDiseases(), 'What the user entered is kept.');
    }

    public function testAnAttemptReplacedByARetryDoesNothing(): void
    {
        $planting = $this->startDrafting(new Planting('Hortensia du portail', null, null, new \DateTimeImmutable('2026-11-01'), 191052, 'Hydrangea paniculata'));
        $staleConversationId = $planting->getConversationId() ?? self::fail();
        $planting->startDrafting(new UuidV7());
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        self::getContainer()->get(DraftCareSheetHandler::class)(new DraftCareSheet($planting->getId(), $staleConversationId));

        self::assertSame([], RecordingHub::published(ChatPublisher::topic($staleConversationId)));
        $planting = $this->reload($planting);
        self::assertNull($planting->getPlant());
        self::assertTrue($planting->isDrafting(), 'The retry goes on.');
    }

    public function testAProviderThatDoesNotAnswerIsAFailureTheUserCanRetry(): void
    {
        $planting = $this->startDrafting(new Planting('Rose, ' . ScriptedGardener::UNREACHABLE, null, null, new \DateTimeImmutable('2026-11-01')));

        $this->handle($planting);

        self::assertSame(
            [['type' => 'start'], ['type' => 'error', 'message' => 'The assistant could not answer: The model provider does not answer.'], ['type' => 'done'], ['type' => 'refresh']],
            $this->events($planting),
            'One error, the one of the provider, then the page shows the failure.',
        );
        $planting = $this->reload($planting);
        self::assertTrue($planting->hasDraftingFailed());
        self::assertNull($planting->getPlant());
    }

    public function testABugIsAFailureTooAndIsNotHidden(): void
    {
        $planting = $this->startDrafting(new Planting(ScriptedGardener::BROKEN, null, null, new \DateTimeImmutable('2026-11-01')));

        try {
            $this->handle($planting);
            self::fail('A bug is not hidden.');
        } catch (\LogicException $e) {
            self::assertSame('The agent is broken.', $e->getMessage());
        }

        $events = $this->events($planting);
        self::assertSame('The assistant could not answer, see the logs of the worker.', $events[1]['message'] ?? null);
        self::assertSame(['type' => 'refresh'], $events[\count($events) - 1], 'The page shows the failure.');
        self::assertTrue($this->reload($planting)->hasDraftingFailed(), 'The user may retry.');
    }

    public function testAPlantingDeletedMeanwhileIsSkipped(): void
    {
        $planting = $this->startDrafting(new Planting('Hortensia du portail', null, null, new \DateTimeImmutable('2026-11-01'), 191052, 'Hydrangea paniculata'));
        $message = new DraftCareSheet($planting->getId(), $planting->getConversationId() ?? self::fail());
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->remove($planting);
        $entityManager->flush();

        self::getContainer()->get(DraftCareSheetHandler::class)($message);

        self::assertSame([], RecordingHub::published(ChatPublisher::topic($message->conversationId)));
    }

    public function testAPlantingLinkedMeanwhileIsSkipped(): void
    {
        $planting = $this->startDrafting(new Planting('Hortensia du portail', null, null, new \DateTimeImmutable('2026-11-01'), 191052, 'Hydrangea paniculata'));
        $plant = PlantFactory::validated();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($plant);
        $planting->linkTo($plant);
        $entityManager->flush();

        $this->handle($planting);

        self::assertSame([], $this->events($planting));
        self::assertSame($plant->getId(), $this->reload($planting)->getPlant()?->getId());
    }

    private function startDrafting(Planting $planting): Planting
    {
        $planting->startDrafting(new UuidV7());
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($planting);
        $entityManager->flush();

        return $planting;
    }

    private function handle(Planting $planting): void
    {
        self::getContainer()->get(DraftCareSheetHandler::class)(new DraftCareSheet($planting->getId(), $planting->getConversationId() ?? self::fail()));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function events(Planting $planting): array
    {
        return ChatStreams::of($planting->getConversationId() ?? self::fail());
    }

    private function reload(Planting $planting): Planting
    {
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        return self::getContainer()->get(PlantingRepository::class)->find($planting->getId()) ?? self::fail('The planting is gone.');
    }
}
