<?php

namespace App\Plant\Exception;

final class PlantAlreadyValidatedException extends \RuntimeException
{
    public static function withId(int $id): self
    {
        return new self(\sprintf('Plant %d is already validated.', $id));
    }
}
