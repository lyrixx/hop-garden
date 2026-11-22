<?php

namespace App\Garden\Validator;

use Symfony\Component\Validator\Constraint;

/**
 * The name of a space created from a card of the agent: not one the garden has already, whatever its case. Two cards
 * may propose the same space, in two conversations, and the user tap both.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class NewSpaceName extends Constraint
{
    public string $message = 'space.name.taken';
}
