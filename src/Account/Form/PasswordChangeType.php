<?php

namespace App\Account\Form;

use App\Account\Form\Model\PasswordChangeFormData;
use App\Account\Validator\NewPassword;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<PasswordChangeFormData>
 */
final class PasswordChangeType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('currentPassword', PasswordType::class, ['label' => 'account.form.current_password', 'empty_data' => '', 'attr' => ['autocomplete' => 'current-password']])
            ->add('newPassword', PasswordType::class, ['label' => 'account.form.new_password', 'empty_data' => '', 'help' => 'account.form.password_help', 'help_translation_parameters' => ['min' => NewPassword::MIN_LENGTH], 'attr' => ['autocomplete' => 'new-password']])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => PasswordChangeFormData::class]);
    }
}
