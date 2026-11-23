<?php

namespace App\Garden;

use App\Account\Entity\User;
use App\Chat\ConversationRepository;
use App\Garden\Entity\Garden;
use App\Garden\Entity\Membership;
use App\Reminder\NotificationRepository;
use Symfony\AI\Agent\MessageStore\MessageStoreInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * A member leaves a garden, or its owner removes them: their conversations with the assistant in it and their
 * notifications of it go with them, what they added to it stays with the others (Garden::leave()). Deleted at once, the
 * messages in the table of the message store as the rows: the caller flushes, in a transaction.
 */
final readonly class MemberDeparture
{
    public function __construct(
        private ConversationRepository $conversationRepository,
        private NotificationRepository $notificationRepository,
        // Not #[Target('chat')]: see App\Chat\ChatHistory
        #[Autowire(service: 'ai.message_store.doctrine.dbal.chat')]
        private MessageStoreInterface $messageStore,
    ) {
    }

    public function leave(Garden $garden, User $user): void
    {
        $garden->leave($user);
        $this->forget($garden, $user);
    }

    public function remove(Garden $garden, Membership $membership): void
    {
        $garden->remove($membership);
        $this->forget($garden, $membership->getUser());
    }

    private function forget(Garden $garden, User $user): void
    {
        // Not rows of Doctrine: no foreign key deletes them (App\Chat\Entity\Conversation)
        foreach ($this->conversationRepository->findIdsOf($user, $garden) as $conversationId) {
            $this->messageStore->delete($conversationId);
        }
        $this->conversationRepository->deleteOf($user, $garden);
        $this->notificationRepository->deleteOf($user, $garden);
    }
}
