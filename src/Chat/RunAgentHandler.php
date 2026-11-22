<?php

namespace App\Chat;

use App\Chat\Message\RunAgent;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Translation\LocaleSwitcher;

#[AsMessageHandler]
final readonly class RunAgentHandler
{
    public function __construct(
        private AgentRunner $agentRunner,
        private LocaleSwitcher $localeSwitcher,
    ) {
    }

    /**
     * In the locale of the user who sent it: the agent answers in it, the worker renders the cards in it. Back to the
     * default one after, for the next message.
     */
    public function __invoke(RunAgent $message): void
    {
        $this->localeSwitcher->runWithLocale($message->locale, fn () => $this->agentRunner->run($message->conversationId, $message->text));
    }
}
