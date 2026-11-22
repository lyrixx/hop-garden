<?php

namespace App\Garden;

use App\Chat\AgentRunner;
use App\Chat\ChatPublisher;
use App\Chat\Model\AgentRun;
use App\Chat\Model\ChatEvent;
use App\Garden\Entity\Planting;
use App\Garden\Message\DraftCareSheet;
use App\Plant\PlantRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The agent drafts the care sheet through MCP, as in the chat, and the planting is linked to the draft as soon as
 * propose_plant succeeds. A run without draft is a failure: the user may retry, nothing they entered is lost.
 *
 * The page of the planting follows the tool calls, then shows the summary of the draft, streamed in place. A failure
 * refreshes the page instead: the worker cannot render its Retry button, it has no session to sign its form.
 */
#[AsMessageHandler]
final readonly class DraftCareSheetHandler
{
    public function __construct(
        private AgentRunner $agentRunner,
        private PlantingRepository $plantingRepository,
        private PlantRepository $plantRepository,
        private EntityManagerInterface $entityManager,
        private ChatPublisher $publisher,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(DraftCareSheet $message): void
    {
        $planting = $this->currentAttempt($message);
        if (null === $planting) {
            $this->logger->info('Care sheet draft skipped: the planting is gone, linked, or retried since.', ['planting_id' => $message->plantingId]);

            return;
        }

        $run = null;

        try {
            $run = $this->agentRunner->run($message->conversationId, CareSheetDraftPrompt::for($planting), function (int $plantId) use ($message): void {
                $planting = $this->currentAttempt($message);
                if (null !== $planting) {
                    $planting->linkTo($this->plantRepository->get($plantId));
                    $this->entityManager->flush();
                    $this->publisher->publish($message->conversationId, ChatEvent::CareSheetDrafted, ['planting' => $planting]);
                }
            });
        } finally {
            if (!$run instanceof AgentRun || [] === $run->proposedPlantIds) {
                $planting = $this->currentAttempt($message);
                if (null !== $planting) {
                    $planting->failDrafting();
                    $this->entityManager->flush();
                    $this->publisher->publish($message->conversationId, ChatEvent::Refresh);
                }
            }
        }

        if ($run->completed && [] === $run->proposedPlantIds) {
            $this->logger->warning('The gardener agent ended without drafting a care sheet.', ['planting_id' => $message->plantingId]);
        }
    }

    /**
     * Read again at each step: a run lasts long enough for the user to retry, or to link another care sheet.
     */
    private function currentAttempt(DraftCareSheet $message): ?Planting
    {
        $planting = $this->plantingRepository->find($message->plantingId);
        if (null === $planting) {
            return null;
        }

        $this->entityManager->refresh($planting);

        return $planting->isDraftedIn($message->conversationId) ? $planting : null;
    }
}
