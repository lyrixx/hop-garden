<?php

namespace App\Garden;

use App\Account\UserRepository;
use App\Chat\AgentRunner;
use App\Chat\ChatPublisher;
use App\Chat\Model\AgentRun;
use App\Chat\Model\ChatEvent;
use App\Garden\Entity\Garden;
use App\Garden\Entity\Planting;
use App\Garden\Message\DraftCareSheet;
use App\Mcp\Agent\AgentRunTokens;
use App\Mcp\Agent\Exception\InvalidAgentRunTokenException;
use App\Mercure\Topics;
use App\Plant\PlantRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Translation\LocaleSwitcher;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * The agent drafts the care sheet through MCP, as in the chat, and the planting is linked to the draft as soon as
 * propose_plant succeeds. A run without draft is a failure: the user may retry, nothing they entered is lost.
 *
 * The page of the planting follows the tool calls, then shows the summary of the draft, streamed in place. A failure
 * refreshes the page instead: the worker cannot render its Retry button, it has no session to sign its form.
 *
 * In the locale of the user who added the plant: the agent drafts the care sheet in it. In the garden of the planting,
 * while they are a member of it, and while the token of the run is valid: the page of the planting shows the outcome to
 * every member (App\Mercure\Topics::drafting()).
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
        private LocaleSwitcher $localeSwitcher,
        private UserRepository $userRepository,
        private AgentRunTokens $agentRunTokens,
        private Topics $topics,
    ) {
    }

    public function __invoke(DraftCareSheet $message): void
    {
        $this->localeSwitcher->runWithLocale($message->locale, fn () => $this->draft($message));
    }

    private function draft(DraftCareSheet $message): void
    {
        $planting = $this->currentAttempt($message);
        if (null === $planting) {
            $this->logger->info('Care sheet draft skipped: the planting is gone, linked, or retried since.', ['planting_id' => $message->plantingId]);

            return;
        }

        $garden = $planting->getGarden();
        $topic = $this->topics->drafting($garden, $message->conversationId);
        if (!$this->mayRun($message, $garden)) {
            $planting->failDrafting();
            $this->entityManager->flush();
            $this->publisher->publish($topic, ChatEvent::Refresh);

            return;
        }

        $run = null;

        try {
            $run = $this->agentRunner->run($topic, $message->conversationId, $message->agentRunToken, CareSheetDraftPrompt::for($planting), function (Uuid $plantId) use ($message, $garden, $topic): void {
                $planting = $this->currentAttempt($message);
                $plant = $this->plantRepository->findOneInGarden($garden, $plantId);
                if (null !== $planting && null !== $plant) {
                    $planting->linkTo($plant);
                    $this->entityManager->flush();
                    // Rendered again by the browser of each member on the page, in their language
                    $this->publisher->publish($topic, ChatEvent::Refresh);
                }
            });
        } finally {
            if (!$run instanceof AgentRun || [] === $run->proposedPlantIds) {
                $planting = $this->currentAttempt($message);
                if (null !== $planting) {
                    $planting->failDrafting();
                    $this->entityManager->flush();
                    $this->publisher->publish($topic, ChatEvent::Refresh);
                }
            }
        }

        if ($run->completed && [] === $run->proposedPlantIds) {
            $this->logger->warning('The gardener agent ended without drafting a care sheet.', ['planting_id' => $message->plantingId]);
        }
    }

    /**
     * The one who added the plant is still a member of its garden, and the token of the run is valid: a failure
     * otherwise, which the user may retry.
     */
    private function mayRun(DraftCareSheet $message, Garden $garden): bool
    {
        $user = $this->userRepository->find(Uuid::fromString($message->userId));
        if (null === $user || !$garden->isMember($user)) {
            $this->logger->warning('Care sheet draft skipped: whoever added the plant is no member of its garden any more.', ['planting_id' => $message->plantingId, 'user_id' => $message->userId]);

            return false;
        }

        try {
            $this->agentRunTokens->verify($message->agentRunToken);
        } catch (InvalidAgentRunTokenException $e) {
            $this->logger->warning('Care sheet draft skipped: the token of its run is no longer valid.', ['exception' => $e, 'planting_id' => $message->plantingId]);

            return false;
        }

        return true;
    }

    /**
     * Read again at each step: a run lasts long enough for the user to retry, or to link another care sheet. Of the
     * garden of the message only.
     */
    private function currentAttempt(DraftCareSheet $message): ?Planting
    {
        $planting = $this->plantingRepository->find(Uuid::fromString($message->plantingId));
        if (null === $planting || $planting->getGarden()->getId()->toRfc4122() !== $message->gardenId) {
            return null;
        }

        $this->entityManager->refresh($planting);

        return $planting->isDraftedIn(UuidV7::fromString($message->conversationId)) ? $planting : null;
    }
}
