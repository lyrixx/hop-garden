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
            ->add('name', TextType::class, ['label' => 'Name', 'empty_data' => '', 'attr' => ['placeholder' => 'Balcony, north bed, vegetable patch…', 'autocomplete' => 'off']])
            ->add('exposure', EnumType::class, [
                'class' => Exposure::class,
                'expanded' => true,
                'required' => false,
                'placeholder' => 'Not sure',
                'choice_label' => static fn (Exposure $exposure): string => $exposure->label(),
                'help' => 'The sun it gets on a summer day.',
            ])
            ->add('soil', EnumType::class, [
                'class' => Soil::class,
                'expanded' => true,
                'required' => false,
                'placeholder' => 'Not sure',
                'choice_label' => static fn (Soil $soil): string => $soil->label(),
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => SpaceFormData::class]);
    }
}
