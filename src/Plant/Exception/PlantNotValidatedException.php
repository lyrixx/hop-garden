<?php

namespace App\Plant\Exception;

use Symfony\Component\Uid\Uuid;

final class PlantNotValidatedException extends \RuntimeException
{
    public static function forReminders(Uuid $id): self
    {
        return new self(\sprintf('Plant %s is still a draft: the user must validate it before reminders can be set.', $id));
    }
}
