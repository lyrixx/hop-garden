<?php

namespace App\Plant\Form;

use App\Locale\IntlFormatter;
use App\Plant\Form\Model\CareSheetFormData;
use App\Plant\Model\CareSheetField;
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
    public function __construct(
        private readonly IntlFormatter $formatter,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $months = [];
        foreach (range(1, 12) as $month) {
            $months[$this->formatter->monthName($month)] = $month;
        }
        // In the language of the page already
        $monthOptions = ['choices' => $months, 'choice_translation_domain' => false, 'multiple' => true, 'expanded' => true];

        $builder
            ->add('commonName', TextType::class, ['label' => 'form.name', 'empty_data' => ''])
            ->add('minTemperatureC', IntegerType::class, ['label' => 'care_sheet.form.hardiness'])
            ->add('frostSensitive', CheckboxType::class, ['label' => CareSheetField::FrostSensitive, 'required' => false])
            ->add('exposure', EnumType::class, ['label' => CareSheetField::Exposure, 'class' => Exposure::class])
            ->add('watering', EnumType::class, ['label' => CareSheetField::Watering, 'class' => Watering::class])
            ->add('pruningMonths', ChoiceType::class, ['label' => CareSheetField::PruningMonths, ...$monthOptions])
            ->add('deadheadingMonths', ChoiceType::class, ['label' => CareSheetField::DeadheadingMonths, ...$monthOptions])
            ->add('cuttingMonths', ChoiceType::class, ['label' => CareSheetField::CuttingMonths, ...$monthOptions])
            ->add('pruningAdvice', TextareaType::class, ['label' => CareSheetField::PruningAdvice, 'required' => false])
            ->add('cuttingAdvice', TextareaType::class, ['label' => CareSheetField::CuttingAdvice, 'required' => false])
            ->add('notes', TextareaType::class, ['label' => 'care_sheet.form.notes', 'required' => false])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => CareSheetFormData::class]);
    }
}
