<?php

namespace App\Chat;

use App\Account\UserRepository;
use App\Chat\Message\RunAgent;
use App\Chat\Model\ChatEvent;
use App\Garden\GardenRepository;
use App\Mcp\Agent\AgentRunTokens;
use App\Mcp\Agent\Exception\InvalidAgentRunTokenException;
use App\Mercure\Topics;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Translation\LocaleSwitcher;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsMessageHandler]
final readonly class RunAgentHandler
{
    public function __construct(
        private AgentRunner $agentRunner,
        private LocaleSwitcher $localeSwitcher,
        private UserRepository $userRepository,
        private GardenRepository $gardenRepository,
        private AgentRunTokens $agentRunTokens,
        private Topics $topics,
        private ChatPublisher $publisher,
        private TranslatorInterface $translator,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * In the locale of the user who sent it: the agent answers in it, the worker renders the cards in it. Back to the
     * default one after, for the next message. Only while they are a member of the garden (they may have been removed
     * or have left since), and while the token of the run is valid: every tool would refuse it.
     */
    public function __invoke(RunAgent $message): void
    {
        $user = $this->userRepository->find(Uuid::fromString($message->userId));
        if (null === $user) {
            $this->logger->info('Chat message skipped: its sender is gone.', ['user_id' => $message->userId]);

            return;
        }

        $this->localeSwitcher->runWithLocale($message->locale, function () use ($message, $user): void {
            $topic = $this->topics->conversation($user, $message->conversationId);

            $garden = $this->gardenRepository->findOneOfMember(Uuid::fromString($message->gardenId), $user);
            if (null === $garden) {
                $this->logger->warning('Chat message skipped: its sender is no member of its garden any more.', ['user_id' => $message->userId, 'garden_id' => $message->gardenId]);
                $this->publisher->publish($topic, ChatEvent::Error, ['message' => $this->translator->trans('chat.error.not_a_member')]);

                return;
            }

            try {
                $this->agentRunTokens->verify($message->agentRunToken);
            } catch (InvalidAgentRunTokenException $e) {
                $this->logger->warning('Chat message skipped: the token of its run is no longer valid.', ['exception' => $e]);
                $this->publisher->publish($topic, ChatEvent::Error, ['message' => $this->translator->trans('chat.error.expired')]);

                return;
            }

            $this->agentRunner->run($garden, $topic, $message->conversationId, $message->agentRunToken, $message->text);
        });
    }
}
