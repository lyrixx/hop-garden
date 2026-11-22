<?php

namespace App\Account\Form\Model;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * "I forgot my password": the address of the account.
 */
final class EmailFormData
{
    #[Assert\NotBlank]
    #[Assert\Email]
    public string $email = '';
}
