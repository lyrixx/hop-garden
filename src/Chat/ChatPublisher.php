<?php

namespace App\Chat;

use App\Chat\Model\ChatEvent;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\Exception\RuntimeException as MercureException;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Twig\Environment;

/**
 * Pushes the Turbo Streams of a run of the agent to its Mercure topic, where its page listens: the conversation of the
 * chat, or the drafting of a care sheet (App\Mercure\Topics). Private updates: only a subscriber the topic is granted
 * to receives them.
 */
final readonly class ChatPublisher
{
    public function __construct(
        private HubInterface $hub,
        private Environment $twig,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $context of the stream template of the event
     */
    public function publish(string $topic, ChatEvent $event, array $context = []): void
    {
        $streams = $this->twig->render('chat/stream/' . $event->value . '.stream.html.twig', $context);

        try {
            $this->hub->publish(new Update($topic, $streams, private: true));
        } catch (MercureException $e) {
            // The answer is stored in the conversation anyway: a reload shows it
            $this->logger->warning('Could not push a chat event.', ['exception' => $e, 'event' => $event->value]);
        }
    }
}
