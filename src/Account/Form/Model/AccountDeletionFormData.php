<?php

namespace App\Account\Form\Model;

use Symfony\Component\Security\Core\Validator\Constraints\UserPassword;

final class AccountDeletionFormData
{
    #[UserPassword(message: 'account.password.wrong')]
    public string $currentPassword = '';
}
