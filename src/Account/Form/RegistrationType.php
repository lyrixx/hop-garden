<?php

namespace App\Account\Form;

use App\Account\Form\Model\RegistrationFormData;
use App\Account\Validator\NewPassword;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<RegistrationFormData>
 */
final class RegistrationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('email', EmailType::class, ['label' => 'account.form.email', 'empty_data' => '', 'attr' => ['autocomplete' => 'email']])
            ->add('displayName', TextType::class, ['label' => 'account.form.display_name', 'empty_data' => '', 'help' => 'account.form.display_name_help', 'attr' => ['autocomplete' => 'name']])
            ->add('plainPassword', PasswordType::class, ['label' => 'account.form.password', 'empty_data' => '', 'help' => 'account.form.password_help', 'help_translation_parameters' => ['min' => NewPassword::MIN_LENGTH], 'attr' => ['autocomplete' => 'new-password']])
            // Its label carries the link to the terms: templates/registration/register.html.twig
            ->add('acceptsTerms', CheckboxType::class, ['required' => true])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => RegistrationFormData::class]);
    }
}
