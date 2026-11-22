<?php

namespace App\Account\Form\Model;

use App\Account\Validator\NewPassword;

/**
 * The new password of a reset, from the link of the email.
 */
final class NewPasswordFormData
{
    #[NewPassword]
    public string $plainPassword = '';
}
