<?php

namespace App\Mcp\Resource;

use App\Garden\CurrentGarden;
use App\Locale\Model\SupportedLocale;
use App\Mcp\PlantNormalizer;
use App\Plant\PlantRepository;
use Mcp\Capability\Attribute\McpResource;
use Mcp\Capability\Attribute\McpResourceTemplate;
use Mcp\Exception\ResourceReadException;
use Symfony\Component\Translation\LocaleSwitcher;
use Symfony\Component\Uid\Uuid;
use Twig\Environment;

/**
 * In English, as the tools and their results: the MCP server speaks to a model, whatever the language of the page.
 */
final readonly class CollectionResources
{
    public function __construct(
        private PlantRepository $plantRepository,
        private PlantNormalizer $plantNormalizer,
        private Environment $twig,
        private LocaleSwitcher $localeSwitcher,
        private CurrentGarden $currentGarden,
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
        return $this->render('mcp/collection.md.twig', [
            'plants' => $this->plantRepository->findValidated($this->currentGarden->get()),
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
        // The same answer for a plant of another garden: it is not said to exist
        $plant = Uuid::isValid($id) ? $this->plantRepository->findOneInGarden($this->currentGarden->get(), Uuid::fromString($id)) : null;
        if (null === $plant) {
            throw new ResourceReadException(\sprintf('No plant with id "%s".', $id));
        }

        return $this->render('mcp/plant.md.twig', [
            'plant' => $plant,
            'url' => $this->plantNormalizer->urlOf($plant),
        ]);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function render(string $template, array $context): string
    {
        return $this->localeSwitcher->runWithLocale(SupportedLocale::English->value, fn (): string => $this->twig->render($template, $context));
    }
}
