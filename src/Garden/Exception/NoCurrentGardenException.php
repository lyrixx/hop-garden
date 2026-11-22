<?php

namespace App\Garden\Exception;

/**
 * A member logged in on a page of a garden before they made one: App\Garden\NoGardenListener sends them to the wizard.
 */
final class NoCurrentGardenException extends \RuntimeException
{
    public static function create(): self
    {
        return new self('No current garden: a member creates one first, and a bound token keeps its own.');
    }
}
