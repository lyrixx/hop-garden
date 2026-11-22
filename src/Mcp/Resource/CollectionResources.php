<?php

namespace App\Mcp\Resource;

use App\Mcp\PlantNormalizer;
use App\Plant\PlantRepository;
use Mcp\Capability\Attribute\McpResource;
use Mcp\Capability\Attribute\McpResourceTemplate;
use Mcp\Exception\ResourceReadException;
use Twig\Environment;

final readonly class CollectionResources
{
    public function __construct(
        private PlantRepository $plantRepository,
        private PlantNormalizer $plantNormalizer,
        private Environment $twig,
    ) {
    }

    #[McpResource(
        uri: 'hopgarden://collection',
        name: 'collection',
        title: 'Plant collection',
        description: 'The validated plants of the garden (one care sheet per species), with their reminders, in Markdown.',
        mimeType: 'text/markdown',
    )]
    public function collection(): string
    {
        return $this->twig->render('mcp/collection.md.twig', [
            'plants' => $this->plantRepository->findValidated(),
            'normalizer' => $this->plantNormalizer,
        ]);
    }

    #[McpResourceTemplate(
        uriTemplate: 'hopgarden://plants/{id}',
        name: 'plant',
        title: 'Plant care sheet',
        description: 'The care sheet of a plant (draft or validated), in Markdown, with the confidence and the sources of its values.',
        mimeType: 'text/markdown',
    )]
    public function plant(string $id): string
    {
        $plant = ctype_digit($id) ? $this->plantRepository->find((int) $id) : null;
        if (null === $plant) {
            throw new ResourceReadException(\sprintf('No plant with id "%s".', $id));
        }

        return $this->twig->render('mcp/plant.md.twig', [
            'plant' => $plant,
            'url' => $this->plantNormalizer->urlOf($plant),
        ]);
    }
}
