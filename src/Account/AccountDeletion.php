<?php

namespace App\Account;

use App\Account\Entity\User;
use App\Chat\ConversationRepository;
use App\Garden\GardenRepository;
use App\Garden\PlantingRepository;
use Symfony\AI\Agent\MessageStore\MessageStoreInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\UuidV7;

/**
 * A member deletes their account: a garden they were the only member of goes with it, its plants, spaces, care sheets,
 * conversations and photos; a garden they share stays with the others, the oldest of them its owner if they were
 * (Garden::leave()). Their own conversations go too. The caller flushes, in a transaction, then removes the photos.
 */
final readonly class AccountDeletion
{
    public function __construct(
        private UserRepository $userRepository,
        private GardenRepository $gardenRepository,
        private PlantingRepository $plantingRepository,
        private ConversationRepository $conversationRepository,
        // Not #[Target('chat')]: see App\Chat\ChatHistory
        #[Autowire(service: 'ai.message_store.doctrine.dbal.chat')]
        private MessageStoreInterface $messageStore,
    ) {
    }

    /**
     * @return list<string> the photos of the gardens deleted with the account, to remove once it is flushed
     */
    public function delete(User $user): array
    {
        $photos = [];
        foreach ($this->gardenRepository->findOfMember($user) as $garden) {
            if ([$user] !== $garden->getMembers()) {
                $garden->leave($user);

                continue;
            }

            array_push($photos, ...$this->plantingRepository->findPhotoFilenames($garden));
            $this->deleteMessages($this->conversationRepository->findIdsOfGarden($garden));
            $this->gardenRepository->remove($garden);
        }

        // Theirs in the gardens they leave; the rows go with the account, by the foreign keys
        $this->deleteMessages($this->conversationRepository->findIdsOfUser($user));
        $this->userRepository->remove($user);

        return $photos;
    }

    /**
     * The messages of the conversations, in the table of the message store: not rows of Doctrine, which the foreign
     * keys would delete.
     *
     * @param list<UuidV7> $conversationIds
     */
    private function deleteMessages(array $conversationIds): void
    {
        foreach ($conversationIds as $conversationId) {
            $this->messageStore->delete($conversationId);
        }
    }
}
