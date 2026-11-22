<?php

namespace App\Garden;

use App\Garden\Entity\Planting;
use App\Garden\Entity\Space;
use App\Plant\Entity\Plant;
use App\Plant\PlantRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsTargetedValueResolver;
use Symfony\Component\HttpKernel\Controller\ValueResolverInterface;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A species, a plant or a space of the URL (/g/{garden}/species/{plant}, /garden/{planting}, /spaces/{space}): by its
 * slug, in the garden of the URL only, else a 404, the same as one that does not exist. #[MapEntity] would take the slug
 * for its UUID. On an argument: #[ValueResolver('in_garden')].
 */
#[AsTargetedValueResolver('in_garden')]
final readonly class InGardenValueResolver implements ValueResolverInterface
{
    public function __construct(
        private CurrentGarden $currentGarden,
        private PlantRepository $plantRepository,
        private PlantingRepository $plantingRepository,
        private SpaceRepository $spaceRepository,
    ) {
    }

    /**
     * @return array{Plant|Planting|Space}
     */
    public function resolve(Request $request, ArgumentMetadata $argument): array
    {
        $slug = $request->attributes->get($argument->getName());
        if (!\is_string($slug)) {
            throw new \LogicException(\sprintf('The route has no {%s} for the argument of the same name.', $argument->getName()));
        }

        $garden = $this->currentGarden->get();
        $resource = match ($argument->getType()) {
            Plant::class => $this->plantRepository->findOneBySlug($garden, $slug),
            Planting::class => $this->plantingRepository->findOneBySlug($garden, $slug),
            Space::class => $this->spaceRepository->findOneBySlug($garden, $slug),
            default => throw new \LogicException(\sprintf('No %s is found by its slug in a garden.', $argument->getType() ?? 'argument without type')),
        };

        return [$resource ?? throw new NotFoundHttpException(\sprintf('No %s "%s" in the garden "%s".', $argument->getName(), $slug, $garden->getSlug()))];
    }
}
