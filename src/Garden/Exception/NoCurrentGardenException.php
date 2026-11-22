<?php

namespace App\Garden\Exception;

/**
 * Out of a garden: a page without one in its URL, or a token bound to a garden its member left.
 */
final class NoCurrentGardenException extends \RuntimeException
{
    public static function create(): self
    {
        return new self('No current garden: a page of a garden has one in its URL, and a bound token keeps its own.');
    }
}
