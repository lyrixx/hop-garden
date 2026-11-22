<?php

namespace App\Plant\Exception;

final class PlantNotFoundException extends \RuntimeException
{
    public static function withId(int $id): self
    {
        return new self(\sprintf('No plant with id %d.', $id));
    }
}
