<?php

namespace App\Garden\Security;

use App\Account\Entity\User;
use App\Garden\CurrentGarden;
use App\Garden\Entity\Garden;
use App\Garden\Entity\Planting;
use App\Garden\Entity\Space;
use App\Plant\Entity\Plant;
use App\Reminder\Entity\Notification;
use App\Reminder\Entity\Reminder;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * What belongs to a garden is seen and changed in that garden only: the current one (App\Garden\CurrentGarden), by
 * its members (GardenVoter). Denied otherwise, and the controllers answer 404: a resource of another garden is not
 * said to exist. A notification is its recipient's own, in their garden.
 *
 * @extends Voter<string, Plant|Planting|Space|Reminder|Notification>
 */
final class GardenResourceVoter extends Voter
{
    public const string PLANT_VIEW = 'PLANT_VIEW';
    public const string PLANT_EDIT = 'PLANT_EDIT';
    public const string PLANTING_VIEW = 'PLANTING_VIEW';
    public const string PLANTING_EDIT = 'PLANTING_EDIT';
    public const string SPACE_VIEW = 'SPACE_VIEW';
    public const string SPACE_EDIT = 'SPACE_EDIT';
    public const string REMINDER_EDIT = 'REMINDER_EDIT';
    public const string NOTIFICATION_VIEW = 'NOTIFICATION_VIEW';

    private const array SUBJECTS = [
        self::PLANT_VIEW => Plant::class,
        self::PLANT_EDIT => Plant::class,
        self::PLANTING_VIEW => Planting::class,
        self::PLANTING_EDIT => Planting::class,
        self::SPACE_VIEW => Space::class,
        self::SPACE_EDIT => Space::class,
        self::REMINDER_EDIT => Reminder::class,
        self::NOTIFICATION_VIEW => Notification::class,
    ];

    private const array EDITS = [self::PLANT_EDIT, self::PLANTING_EDIT, self::SPACE_EDIT, self::REMINDER_EDIT];

    public function __construct(
        private readonly CurrentGarden $currentGarden,
        private readonly AccessDecisionManagerInterface $accessDecisionManager,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        $class = self::SUBJECTS[$attribute] ?? null;

        return null !== $class && $subject instanceof $class;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        $garden = self::gardenOf($subject);
        if ($garden !== $this->currentGarden->find()) {
            return false;
        }

        if ($subject instanceof Notification && $subject->getRecipient() !== $user) {
            return false;
        }

        return $this->accessDecisionManager->decide($token, [\in_array($attribute, self::EDITS, true) ? GardenVoter::EDIT : GardenVoter::VIEW], $garden);
    }

    private static function gardenOf(Plant|Planting|Space|Reminder|Notification $subject): Garden
    {
        return $subject instanceof Reminder ? $subject->getPlant()->getGarden() : $subject->getGarden();
    }
}
