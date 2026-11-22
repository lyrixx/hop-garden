<?php

namespace App\Account\Form;

use App\Account\Form\Model\AccountDeletionFormData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<AccountDeletionFormData>
 */
final class AccountDeletionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('currentPassword', PasswordType::class, ['label' => 'account.form.current_password', 'empty_data' => '', 'attr' => ['autocomplete' => 'current-password']]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => AccountDeletionFormData::class]);
    }
}
