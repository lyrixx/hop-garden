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
     * @param array<string, list<string>>|null $plantingSlugsByPlant preloaded for a list, see
     *                                                               PlantingRepository::findSlugsForPlants()
     *
     * @return ?string null for a care sheet no plant of the garden follows yet
     */
    #[AsTwigFunction('my_plant_path')]
    public function myPlantPath(Plant $plant, ?array $plantingSlugsByPlant = null): ?string
    {
        $garden = $plant->getGarden();
        $plantingSlugs = ($plantingSlugsByPlant ?? $this->plantingRepository->findSlugsForPlants($garden, [$plant]))[$plant->getId()->toRfc4122()] ?? [];

        return match (\count($plantingSlugs)) {
            0 => null,
            1 => $this->urlGenerator->generate('garden_show', ['garden' => $garden->getSlug(), 'planting' => $plantingSlugs[0]]),
            default => $this->urlGenerator->generate('plant_show', ['garden' => $garden->getSlug(), 'plant' => $plant->getSlug(), '_fragment' => 'in-your-garden']),
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

        return null !== $filename ? $this->urlGenerator->generate('garden_photo', ['garden' => $planting->getGarden()->getSlug(), 'planting' => $planting->getSlug(), 'filename' => $filename]) : null;
    }
}
