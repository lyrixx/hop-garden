<?php

namespace App\Plant\Form;

use App\Plant\Form\Model\NewPlantFormData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<NewPlantFormData>
 */
final class NewPlantType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Name',
                'empty_data' => '',
                'attr' => ['placeholder' => 'Hortensia, Rosa canina…', 'autocomplete' => 'off'],
                'help' => 'Common or Latin: Trefle finds the species.',
            ])
            // Filled by the species picker, rendered as hidden inputs: their errors show at the head of the form
            ->add('trefleId', IntegerType::class, ['required' => false, 'error_bubbling' => true])
            ->add('scientificName', HiddenType::class, ['required' => false])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => NewPlantFormData::class]);
    }
}
