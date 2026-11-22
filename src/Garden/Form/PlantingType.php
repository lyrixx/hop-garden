<?php

namespace App\Garden\Form;

use App\Garden\Form\Model\PlantingFormData;
use App\Garden\Model\CareTask;
use App\Garden\Model\Planting;
use App\Garden\Model\Space;
use App\Garden\SpaceRepository;
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

/**
 * @extends AbstractType<PlantingFormData>
 */
final class PlantingType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $careRecords = $builder->create('careRecords', FormType::class, ['label' => 'Care already done', 'required' => false]);
        foreach (CareTask::cases() as $task) {
            $careRecords->add($task->value, DateType::class, [
                'label' => $task->label(),
                'required' => false,
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
            ]);
        }

        /** @var ?Planting $planting */
        $planting = $options['planting'];

        $builder->add('name', TextType::class, ['label' => 'Name', 'empty_data' => '', 'attr' => ['placeholder' => 'Hortensia, rose by the gate…', 'autocomplete' => 'off']]);

        // Filled by the species picker, rendered as hidden inputs: once planted, the species stays
        if (null === $planting) {
            $builder
                ->add('trefleId', IntegerType::class, ['required' => false])
                ->add('scientificName', HiddenType::class, ['required' => false])
            ;
        }

        $builder
            ->add('photo', FileType::class, [
                'required' => false,
                'attr' => ['accept' => implode(',', PlantingFormData::PHOTO_MIME_TYPES)],
                'help' => 'JPEG, PNG or WebP, up to 10 MB. Without a photo, a picto stands in.',
            ])
            ->add('space', EntityType::class, [
                'class' => Space::class,
                'required' => false,
                'placeholder' => 'Not in a space yet',
                'choice_label' => static function (Space $space): string {
                    $conditions = $space->describeConditions();

                    return null !== $conditions ? \sprintf('%s · %s', $space->getName(), $conditions) : $space->getName();
                },
                'query_builder' => static fn (SpaceRepository $spaceRepository) => $spaceRepository->createOrderedQueryBuilder(),
                'help' => 'Where it grows today: it gets the exposure and the soil of the space, and the plants there are its neighbours.',
            ])
            ->add('diseases', TextareaType::class, [
                'label' => 'Diseases or pests',
                'required' => false,
                'attr' => ['placeholder' => 'Powdery mildew on the lower leaves…'],
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
        ]);
        $resolver->setAllowedTypes('planting', ['null', Planting::class]);
    }
}
