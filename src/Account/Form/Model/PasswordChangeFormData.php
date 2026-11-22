<?php

namespace App\Account\Form\Model;

use App\Account\Validator\NewPassword;
use Symfony\Component\Security\Core\Validator\Constraints\UserPassword;

final class PasswordChangeFormData
{
    #[UserPassword(message: 'account.password.wrong')]
    public string $currentPassword = '';

    #[NewPassword]
    public string $newPassword = '';
}
