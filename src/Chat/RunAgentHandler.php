<?php

namespace App\Chat;

use App\Chat\Message\RunAgent;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class RunAgentHandler
{
    public function __construct(
        private AgentRunner $agentRunner,
    ) {
    }

    public function __invoke(RunAgent $message): void
    {
        $this->agentRunner->run($message->conversationId, $message->text);
    }
}
