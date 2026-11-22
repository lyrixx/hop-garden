<?php

namespace App\Garden\Form;

use App\DataSource\Demo\RecordedResponses;
use App\DataSource\Exception\DataSourceUnavailableException;
use App\DataSource\OpenMeteo\GeocodingClient;
use App\DataSource\OpenMeteo\Model\Place;
use App\Garden\Form\Model\GardenFormData;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Translation\LocaleSwitcher;

/**
 * The name and the place of a garden, without JavaScript: "Find" searches the city (its name, its postcode, or both) with
 * the geocoding of Open-Meteo, then the form offers the places found, one to pick. The places come from Open-Meteo again
 * on each submission, never from the browser, which sends the id of its pick only. In demo mode, a city that no
 * recording answers gets the ones that do.
 *
 * @extends AbstractType<GardenFormData>
 */
final class GardenType extends AbstractType
{
    public function __construct(
        private readonly GeocodingClient $geocodingClient,
        private readonly LocaleSwitcher $localeSwitcher,
        private readonly RecordedResponses $recordedResponses,
        #[Autowire(env: 'bool:APP_DEMO')]
        private readonly bool $demo,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'garden.form.name.label',
                'empty_data' => '',
                'attr' => ['placeholder' => 'garden.form.name.placeholder', 'autocomplete' => 'off'],
            ])
            ->add('city', TextType::class, [
                'label' => 'garden.form.city.label',
                'required' => false,
                'help' => 'garden.form.city.help',
                'attr' => ['placeholder' => 'garden.form.city.placeholder', 'autocomplete' => 'off'],
            ])
            ->add('search', SubmitType::class, [
                'label' => 'garden.form.search',
                'validation_groups' => [GardenFormData::SEARCH],
            ])
            ->addEventListener(FormEvents::PRE_SUBMIT, function (FormEvent $event): void {
                $data = $event->getForm()->getData();
                $submitted = $event->getData();
                if (!$data instanceof GardenFormData || !\is_array($submitted)) {
                    return;
                }

                $city = \is_string($submitted['city'] ?? null) ? trim($submitted['city']) : '';
                $data->places = [];
                $data->placeSearchUnavailable = false;
                $data->demoPlaceQueries = [];
                if ('' !== $city) {
                    try {
                        $data->places = $this->geocodingClient->search($city, $this->localeSwitcher->getLocale());
                    } catch (DataSourceUnavailableException) {
                        // Already logged by the client
                        $data->placeSearchUnavailable = true;
                    }
                }
                if ('' !== $city && [] === $data->places && !$data->placeSearchUnavailable && $this->demo) {
                    $data->demoPlaceQueries = $this->recordedResponses->placeQueries();
                }

                self::addPlaces($event->getForm(), $data->places);

                // The one place of its name: picked already, the user may create the garden at once
                if (1 === \count($data->places) && '' === ($submitted['placeId'] ?? '')) {
                    $event->setData(['placeId' => (string) $data->places[0]->id] + $submitted);
                }
            })
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => GardenFormData::class,
            // Its constructor is private: the controller gives the data
            'empty_data' => null,
        ]);
    }

    /**
     * @param FormInterface<GardenFormData> $form
     * @param list<Place>                   $places
     */
    private static function addPlaces(FormInterface $form, array $places): void
    {
        if ([] === $places) {
            return;
        }

        $suggestions = [];
        foreach ($places as $place) {
            $suggestions[$place->id] = $place->suggestion();
        }

        $form->add('placeId', ChoiceType::class, [
            'label' => 'garden.form.place.label',
            // By id: two homonyms may read the same
            'choices' => array_keys($suggestions),
            'choice_label' => static fn (int $id): string => $suggestions[$id],
            'expanded' => true,
            'required' => false,
            'placeholder' => false,
            // Names of places, from Open-Meteo
            'choice_translation_domain' => false,
            // Picked among the new places only: a city searched again offers its own
            'invalid_message' => 'garden.place.not_picked',
        ]);
    }
}
