<?php

namespace App\Plant\Exception;

final class PlantNotValidatedException extends \RuntimeException
{
    public static function forReminders(int $id): self
    {
        return new self(\sprintf('Plant %d is still a draft: the user must validate it before reminders can be set.', $id));
    }
}
