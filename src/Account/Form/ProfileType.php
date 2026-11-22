<?php

namespace App\Account\Form;

use App\Account\Form\Model\ProfileFormData;
use App\Locale\Model\SupportedLocale;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<ProfileFormData>
 */
final class ProfileType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('displayName', TextType::class, ['label' => 'account.form.display_name', 'empty_data' => '', 'help' => 'account.form.display_name_help', 'attr' => ['autocomplete' => 'name']])
            ->add('preferredLocale', EnumType::class, [
                'class' => SupportedLocale::class,
                'expanded' => true,
                'label' => 'account.form.language',
                // Each one in its own name, as in the footer
                'choice_label' => static fn (SupportedLocale $locale): string => $locale->nativeName(),
                'choice_translation_domain' => false,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => ProfileFormData::class]);
    }
}
