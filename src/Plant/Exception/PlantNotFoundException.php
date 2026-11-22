<?php

namespace App\Plant\Exception;

final class PlantNotFoundException extends \RuntimeException
{
    /**
     * The id as given: a model may send anything.
     */
    public static function withId(string $id): self
    {
        return new self(\sprintf('No plant with id %s.', $id));
    }
}
