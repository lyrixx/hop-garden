<?php

namespace App\Garden\Form;

use App\Garden\Form\Model\InvitationFormData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<InvitationFormData>
 */
final class InvitationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        // The address of somebody else: not the one of the browser
        $builder->add('email', EmailType::class, ['label' => 'garden.members.invite.email', 'empty_data' => '', 'attr' => ['autocomplete' => 'off', 'placeholder' => 'garden.members.invite.placeholder']]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => InvitationFormData::class]);
    }
}
