<?php

namespace App\Garden\Form;

use App\Garden\Entity\Planting;
use App\Garden\Entity\Space;
use App\Garden\Form\Model\PlantingFormData;
use App\Garden\Model\CareTask;
use App\Garden\SpaceRepository;
use App\Plant\Entity\Plant;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * @extends AbstractType<PlantingFormData>
 */
final class PlantingType extends AbstractType
{
    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $careRecords = $builder->create('careRecords', FormType::class, ['label' => 'planting.form.care_records.label', 'required' => false]);
        foreach (CareTask::cases() as $task) {
            $careRecords->add($task->value, DateType::class, [
                'label' => $task,
                'required' => false,
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
            ]);
        }

        /** @var ?Planting $planting */
        $planting = $options['planting'];
        /** @var ?Plant $plant */
        $plant = $options['plant'];

        $builder->add('name', TextType::class, ['label' => 'form.name', 'empty_data' => '', 'attr' => ['placeholder' => 'planting.form.name.placeholder', 'autocomplete' => 'off']]);

        // Filled by the species picker, rendered as hidden inputs: once planted, or picked beforehand, the species stays
        if (null === $planting && null === $plant) {
            $builder
                ->add('trefleId', IntegerType::class, ['required' => false])
                ->add('scientificName', HiddenType::class, ['required' => false])
            ;
        }

        $builder
            ->add('photo', FileType::class, [
                'required' => false,
                'attr' => ['accept' => implode(',', PlantingFormData::PHOTO_MIME_TYPES)],
                'label' => 'planting.form.photo.label',
                'help' => 'planting.form.photo.help',
            ])
            ->add('space', EntityType::class, [
                'class' => Space::class,
                'required' => false,
                'label' => 'space.label',
                'placeholder' => 'space.none',
                'choice_label' => function (Space $space): string {
                    $conditions = $space->describeConditions($this->translator);

                    return null !== $conditions ? \sprintf('%s · %s', $space->getName(), $conditions) : $space->getName();
                },
                // Its name is the user's, its conditions translated already
                'choice_translation_domain' => false,
                'query_builder' => static fn (SpaceRepository $spaceRepository) => $spaceRepository->createOrderedQueryBuilder(),
                'help' => 'planting.form.space.help',
            ])
            ->add('diseases', TextareaType::class, [
                'label' => 'planting.diseases',
                'required' => false,
                'attr' => ['placeholder' => 'planting.form.diseases.placeholder'],
            ])
            ->add($careRecords)
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => PlantingFormData::class,
            // The planting to edit, or null for a new one
            'planting' => null,
            // The species of a new planting, picked beforehand on its page: its fields are not in the form
            'plant' => null,
        ]);
        $resolver->setAllowedTypes('planting', ['null', Planting::class]);
        $resolver->setAllowedTypes('plant', ['null', Plant::class]);
    }
}
