<?php

namespace App\Reminder\Notifier\Model;

use App\Account\Entity\User;
use Symfony\Component\Notifier\Recipient\RecipientInterface;

/**
 * A member of the garden of a reminder, to whom the Notifier sends it in the application (InAppChannel).
 */
final readonly class MemberRecipient implements RecipientInterface
{
    public function __construct(
        public User $user,
    ) {
    }
}
