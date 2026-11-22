<?php

namespace App\Plant\Exception;

use Symfony\Component\Uid\Uuid;

final class PlantAlreadyValidatedException extends \RuntimeException
{
    public static function withId(Uuid $id): self
    {
        return new self(\sprintf('Plant %s is already validated.', $id));
    }
}
