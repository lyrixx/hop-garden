<?php

namespace App\Mcp;

use App\Garden\Entity\Garden;
use App\Garden\Entity\Planting;
use App\Garden\Entity\Space;
use App\Garden\Model\PlantingProposal;
use App\Garden\Model\SpaceProposal;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Shapes the spaces of the garden and the cards that propose to add to it, as the MCP tools expose them (snake_case,
 * as in the tool input schemas): their UUIDs, and their readable URL in their garden.
 */
final readonly class GardenNormalizer
{
    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
        #[Autowire(env: 'DEFAULT_URI')]
        private string $defaultUri,
    ) {
    }

    /**
     * @return array{id: string, name: string, exposure: ?string, soil: ?string, plantings: list<string>, url: string}
     */
    public function space(Space $space): array
    {
        return [
            'id' => $space->getId()->toRfc4122(),
            'name' => $space->getName(),
            'exposure' => $space->getExposure()?->value,
            'soil' => $space->getSoil()?->value,
            'plantings' => array_map(static fn (Planting $planting): string => $planting->getName(), $space->getPlantings()),
            'url' => $this->url('space_show', ['garden' => $space->getGarden()->getSlug(), 'space' => $space->getSlug()]),
        ];
    }

    /**
     * @return array<string, mixed> the proposal, and the URL of its card in the garden, where the user adds the plant
     */
    public function plantingProposal(PlantingProposal $proposal, Garden $garden): array
    {
        return $proposal->toArray() + ['validation_url' => $this->url('planting_proposal', ['garden' => $garden->getSlug(), ...$proposal->toArray()])];
    }

    /**
     * @return array<string, mixed> the proposal, and the URL of its card in the garden, where the user creates the space
     */
    public function spaceProposal(SpaceProposal $proposal, Garden $garden): array
    {
        return $proposal->toArray() + ['validation_url' => $this->url('space_proposal', ['garden' => $garden->getSlug(), ...$proposal->toArray()])];
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function url(string $route, array $parameters): string
    {
        return rtrim($this->defaultUri, '/') . $this->urlGenerator->generate($route, $parameters);
    }
}
