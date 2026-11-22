<?php

namespace App\Garden\Form;

use App\Garden\Form\Model\SpaceFormData;
use App\Garden\Model\Soil;
use App\Plant\Model\Exposure;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<SpaceFormData>
 */
final class SpaceType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, ['label' => 'form.name', 'empty_data' => '', 'attr' => ['placeholder' => 'space.form.name.placeholder', 'autocomplete' => 'off']])
            ->add('exposure', EnumType::class, [
                'class' => Exposure::class,
                'expanded' => true,
                'required' => false,
                'placeholder' => 'space.form.not_sure',
                'help' => 'space.form.exposure.help',
            ])
            ->add('soil', EnumType::class, [
                'class' => Soil::class,
                'expanded' => true,
                'required' => false,
                'placeholder' => 'space.form.not_sure',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => SpaceFormData::class]);
    }
}
