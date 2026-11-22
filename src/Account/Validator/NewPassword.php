<?php

namespace App\Account\Validator;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Constraints\Compound;

/**
 * A password someone chooses: at registration, on a reset, in the account.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class NewPassword extends Compound
{
    public const int MIN_LENGTH = 12;

    /**
     * @param array<mixed> $options
     *
     * @return list<\Symfony\Component\Validator\Constraint>
     */
    protected function getConstraints(array $options): array
    {
        return [
            new Assert\NotBlank(),
            // The hashers stop at 4096 bytes
            new Assert\Length(min: self::MIN_LENGTH, max: 4096),
            // Known from a data breach (Have I Been Pwned): refused, unless the service does not answer
            new Assert\NotCompromisedPassword(skipOnError: true),
        ];
    }
}
