<?php

namespace App\Twig;

use App\Garden\Entity\Planting;
use App\Garden\PlantingRepository;
use App\Plant\Entity\Plant;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Attribute\AsTwigFunction;

final readonly class GardenExtension
{
    public function __construct(
        private PlantingRepository $plantingRepository,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * My plant of this species, in the garden. When several plants follow its care sheet, the reminder is about all of
     * them: the page of the species lists them.
     *
     * @param array<int, list<int>>|null $plantingIdsByPlant preloaded for a list, see PlantingRepository::findIdsForPlants()
     *
     * @return ?string null for a care sheet no plant of the garden follows yet
     */
    #[AsTwigFunction('my_plant_path')]
    public function myPlantPath(Plant $plant, ?array $plantingIdsByPlant = null): ?string
    {
        $plantingIds = ($plantingIdsByPlant ?? $this->plantingRepository->findIdsForPlants($plant->getGarden(), [$plant]))[$plant->getId()] ?? [];

        return match (\count($plantingIds)) {
            0 => null,
            1 => $this->urlGenerator->generate('garden_show', ['id' => $plantingIds[0]]),
            default => $this->urlGenerator->generate('plant_show', ['id' => $plant->getId(), '_fragment' => 'in-your-garden']),
        };
    }

    /**
     * Served to the members of its garden only: GardenController::photo().
     *
     * @return ?string null when the user took no photo: the template shows a picto
     */
    #[AsTwigFunction('planting_photo_url')]
    public function plantingPhotoUrl(Planting $planting): ?string
    {
        $filename = $planting->getPhotoFilename();

        return null !== $filename ? $this->urlGenerator->generate('garden_photo', ['id' => $planting->getId(), 'filename' => $filename]) : null;
    }
}
