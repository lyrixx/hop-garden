<?php

namespace App\Chat;

use App\Chat\Model\AgentRun;
use App\Chat\Model\ChatEvent;
use App\Chat\Model\StreamedAnswer;
use Psr\Log\LoggerInterface;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Agent\Exception\ExceptionInterface as AgentException;
use Symfony\AI\Agent\Execution\Update\Progress;
use Symfony\AI\Agent\Execution\Update\Result;
use Symfony\AI\Agent\Toolbox\Event\ToolCallSucceeded;
use Symfony\AI\Platform\Exception\ExceptionInterface as PlatformException;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Uid\UuidV7;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpException;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Runs the gardener agent on a message of a conversation, and streams what it does to the Mercure topic of that
 * conversation, as Turbo Streams: its answer as it comes, MCP tool calls (in demo mode only, see ToolCallDisplay), the
 * cards and choices of its tools (whatever the mode, see ToolCards), then "done", or "error" when it fails.
 */
final readonly class AgentRunner
{
    /**
     * Deltas are pushed in small batches: one Mercure update per token would be wasteful.
     */
    private const int DELTA_BATCH_LENGTH = 24;

    public function __construct(
        #[Target('gardener')]
        private AgentInterface $agent,
        private ChatPublisher $publisher,
        private ToolCallDisplay $toolCallDisplay,
        private EventDispatcherInterface $eventDispatcher,
        private LoggerInterface $logger,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @param ?\Closure(int): void $onDraftProposed called with the plant of each successful propose_plant, before its
     *                                              card is pushed
     */
    public function run(string $conversationId, string $text, ?\Closure $onDraftProposed = null): AgentRun
    {
        $proposedPlantIds = [];
        $answer = new StreamedAnswer();

        $onToolCallSucceeded = function (ToolCallSucceeded $event) use ($conversationId, $onDraftProposed, &$proposedPlantIds, $answer): void {
            $entry = ToolCards::entryOf($event->getDefinition()->getName(), $event->getResult()->getResult());
            if (null === $entry) {
                return;
            }

            if (null !== $entry->plantId) {
                $proposedPlantIds[] = $entry->plantId;
                if (null !== $onDraftProposed) {
                    $onDraftProposed($entry->plantId);
                }
            }
            $this->publisher->publish($conversationId, ToolCards::eventOf($entry), ['entry' => $entry]);
            $answer->close();
        };
        $this->eventDispatcher->addListener(ToolCallSucceeded::class, $onToolCallSucceeded);

        $completed = false;
        $answered = false;

        try {
            $this->publisher->publish($conversationId, ChatEvent::Start);
            $this->stream($conversationId, $text, $answer);
            $completed = true;
            $answered = true;
        } catch (AgentException|PlatformException|HttpException $e) {
            $this->logger->error('The gardener agent failed.', ['exception' => $e]);
            $this->publisher->publish($conversationId, ChatEvent::Error, ['message' => $this->translator->trans('chat.error.failed', ['reason' => $e->getMessage()])]);
            $answered = true;
        } finally {
            $this->eventDispatcher->removeListener(ToolCallSucceeded::class, $onToolCallSucceeded);

            // Whatever went wrong, the page must not wait forever
            if (!$answered) {
                $this->publisher->publish($conversationId, ChatEvent::Error, ['message' => $this->translator->trans('chat.error.unknown')]);
            }
        }

        return new AgentRun($completed, $proposedPlantIds);
    }

    private function stream(string $conversationId, string $text, StreamedAnswer $answer): void
    {
        $execution = $this->agent->call(MessageBag::forId(UuidV7::fromString($conversationId), $text), ['stream' => true]);

        $batch = '';
        $streamedAnswer = false;
        $separator = '';
        foreach ($execution as $update) {
            // A model that does not stream gives its answer at once, at the end
            if ($update instanceof Result) {
                $result = $update->getResult();
                if (!$streamedAnswer && $result instanceof TextResult && '' !== trim($result->getContent())) {
                    $batch .= $result->getContent();
                }

                continue;
            }

            if (!$update instanceof Progress) {
                continue;
            }

            $payload = $update->getPayload();

            if (Progress::STAGE_DELTA === $update->getStage() && $payload instanceof TextDelta) {
                $streamedAnswer = true;
                $batch .= $separator . $payload->getText();
                $separator = '';
                if (mb_strlen($batch) >= self::DELTA_BATCH_LENGTH || str_contains($batch, "\n")) {
                    $this->publishAnswer($conversationId, $answer, $batch);
                    $batch = '';
                }

                continue;
            }

            if (Progress::STAGE_TOOL_CALL === $update->getStage() && $payload instanceof ToolCall) {
                if ('' !== $batch) {
                    $this->publishAnswer($conversationId, $answer, $batch);
                    $batch = '';
                }

                if ($this->toolCallDisplay->isShown()) {
                    $this->publisher->publish($conversationId, ChatEvent::ToolCall, [
                        'summary' => ToolCallSummary::of($payload->getName(), $payload->getArguments()),
                    ]);
                    $answer->close();
                } elseif ($streamedAnswer) {
                    // No tool call line closes the bubble on the page: what the agent says next goes on in it
                    $separator = "\n\n";
                }
                $streamedAnswer = false;
            }
        }

        if ('' !== $batch) {
            $this->publishAnswer($conversationId, $answer, $batch);
        }

        $this->publisher->publish($conversationId, ChatEvent::Done);
    }

    /**
     * The whole answer so far, rendered from its Markdown on the server, as the history renders it.
     */
    private function publishAnswer(string $conversationId, StreamedAnswer $answer, string $batch): void
    {
        $event = $answer->append($batch) ? ChatEvent::AnswerStarted : ChatEvent::AnswerContinued;
        $this->publisher->publish($conversationId, $event, ['answer' => $answer]);
    }
}
