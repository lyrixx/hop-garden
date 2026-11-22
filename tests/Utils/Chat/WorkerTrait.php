<?php

namespace App\Tests\Utils\Chat;

use App\Chat\Message\RunAgent;
use App\Chat\RunAgentHandler;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * What the worker does with the messages of the chat queued by a functional test, in process: without the member of the
 * browser, as the real worker, which has no session.
 */
trait WorkerTrait
{
    private function runTheWorker(): void
    {
        self::getContainer()->get('security.token_storage')->setToken(null);
        foreach ($this->runAgentMessages() as $message) {
            self::getContainer()->get(RunAgentHandler::class)($message);
        }
        $this->transport()->reset();
    }

    /**
     * @return list<RunAgent>
     */
    private function runAgentMessages(): array
    {
        $messages = [];
        foreach ($this->transport()->getSent() as $envelope) {
            $message = $envelope->getMessage();
            if ($message instanceof RunAgent) {
                $messages[] = $message;
            }
        }

        return $messages;
    }

    private function transport(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }
}
