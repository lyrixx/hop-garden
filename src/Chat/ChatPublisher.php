<?php

namespace App\Chat;

use App\Chat\Model\ChatEvent;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\Exception\RuntimeException as MercureException;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Twig\Environment;

/**
 * Pushes the Turbo Streams of a conversation to its Mercure topic, where its page listens.
 */
final readonly class ChatPublisher
{
    public function __construct(
        private HubInterface $hub,
        private Environment $twig,
        private LoggerInterface $logger,
    ) {
    }

    public static function topic(string $conversationId): string
    {
        return 'chat/' . $conversationId;
    }

    /**
     * @param array<string, mixed> $context of the stream template of the event
     */
    public function publish(string $conversationId, ChatEvent $event, array $context = []): void
    {
        $streams = $this->twig->render('chat/stream/' . $event->value . '.stream.html.twig', $context);

        try {
            $this->hub->publish(new Update(self::topic($conversationId), $streams));
        } catch (MercureException $e) {
            // The answer is stored in the conversation anyway: a reload shows it
            $this->logger->warning('Could not push a chat event.', ['exception' => $e, 'event' => $event->value]);
        }
    }
}
