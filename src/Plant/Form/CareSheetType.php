<?php

namespace App\Plant\Form;

use App\Plant\CareSheetPresenter;
use App\Plant\Form\Model\CareSheetFormData;
use App\Plant\Model\Exposure;
use App\Plant\Model\Watering;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<CareSheetFormData>
 */
final class CareSheetType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $months = [];
        foreach (range(1, 12) as $month) {
            $months[CareSheetPresenter::monthName($month)] = $month;
        }

        $builder
            ->add('commonName', TextType::class, ['label' => 'Name', 'empty_data' => ''])
            ->add('minTemperatureC', IntegerType::class, ['label' => 'Hardiness (°C)'])
            ->add('frostSensitive', CheckboxType::class, ['label' => 'Frost sensitive', 'required' => false])
            ->add('exposure', EnumType::class, ['class' => Exposure::class, 'choice_label' => static fn (Exposure $exposure): string => $exposure->label()])
            ->add('watering', EnumType::class, ['class' => Watering::class, 'choice_label' => static fn (Watering $watering): string => $watering->label()])
            ->add('pruningMonths', ChoiceType::class, ['label' => 'Pruning', 'choices' => $months, 'multiple' => true, 'expanded' => true])
            ->add('deadheadingMonths', ChoiceType::class, ['label' => 'Deadheading', 'choices' => $months, 'multiple' => true, 'expanded' => true])
            ->add('cuttingMonths', ChoiceType::class, ['label' => 'Cuttings', 'choices' => $months, 'multiple' => true, 'expanded' => true])
            ->add('pruningAdvice', TextareaType::class, ['label' => 'Pruning advice', 'required' => false])
            ->add('cuttingAdvice', TextareaType::class, ['label' => 'Cutting advice', 'required' => false])
            ->add('notes', TextareaType::class, ['required' => false])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => CareSheetFormData::class]);
    }
}
