<?php

namespace App\Mcp;

use App\Plant\Entity\CareSheet;
use App\Plant\Entity\Plant;
use App\Plant\Model\CareSheetField;
use App\Plant\Model\Source;
use App\Reminder\Model\ReminderType;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Shapes plants as the MCP tools and resources expose them (snake_case, as in the tool input schemas).
 */
final readonly class PlantNormalizer
{
    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
        #[Autowire(env: 'DEFAULT_URI')]
        private string $defaultUri,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(Plant $plant): array
    {
        return [
            'id' => $plant->getId(),
            'common_name' => $plant->getCommonName(),
            'scientific_name' => $plant->getScientificName(),
            'family' => $plant->getFamily(),
            'status' => $plant->getStatus()->value,
            'enabled_reminders' => array_map(static fn (ReminderType $type): string => $type->value, $plant->getEnabledReminderTypes()),
            'url' => $this->urlOf($plant),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function detail(Plant $plant): array
    {
        return $this->summary($plant) + [
            'trefle_id' => $plant->getTrefleId(),
            'image_url' => $plant->getImageUrl(),
            'notes' => $plant->getNotes(),
            'created_at' => $plant->getCreatedAt()->format(DATE_ATOM),
            'validated_at' => $plant->getValidatedAt()?->format(DATE_ATOM),
            'care_sheet' => $this->careSheet($plant->getCareSheet()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function careSheet(CareSheet $careSheet): array
    {
        return [
            CareSheetField::MinTemperatureC->value => $careSheet->getMinTemperatureC(),
            CareSheetField::FrostSensitive->value => $careSheet->isFrostSensitive(),
            CareSheetField::Exposure->value => $careSheet->getExposure()->value,
            CareSheetField::Watering->value => $careSheet->getWatering()->value,
            CareSheetField::PruningMonths->value => $careSheet->getPruningMonths(),
            CareSheetField::DeadheadingMonths->value => $careSheet->getDeadheadingMonths(),
            CareSheetField::CuttingMonths->value => $careSheet->getCuttingMonths(),
            CareSheetField::PruningAdvice->value => $careSheet->getPruningAdvice(),
            CareSheetField::CuttingAdvice->value => $careSheet->getCuttingAdvice(),
            'confidence' => array_map(static fn ($confidence): string => $confidence->value, $careSheet->getConfidences()),
            'sources' => array_map(static fn (Source $source): array => ['name' => $source->name, 'url' => $source->url], $careSheet->getSources()),
        ];
    }

    public function urlOf(Plant $plant): string
    {
        return rtrim($this->defaultUri, '/') . $this->urlGenerator->generate('plant_show', ['id' => $plant->getId()]);
    }
}
