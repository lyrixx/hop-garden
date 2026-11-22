<?php

namespace App\Account\Form;

use App\Account\Form\Model\EmailFormData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<EmailFormData>
 */
final class PasswordResetRequestType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('email', EmailType::class, ['label' => 'account.form.email', 'empty_data' => '', 'attr' => ['autocomplete' => 'email']]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => EmailFormData::class]);
    }
}
