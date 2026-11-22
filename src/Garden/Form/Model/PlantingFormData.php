<?php

namespace App\Garden\Form\Model;

use App\Garden\Entity\Garden;
use App\Garden\Entity\Planting;
use App\Garden\Entity\Space;
use App\Garden\Model\CareTask;
use App\Plant\Entity\Plant;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * What the user sees in the garden. The species is optional: a name Trefle does not know is kept as is. Once
 * planted, the species stays.
 */
final class PlantingFormData implements SpeciesPickerData
{
    public const array PHOTO_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    public string $name = '';

    #[Assert\Positive]
    public ?int $trefleId = null;

    #[Assert\Length(max: 255)]
    public ?string $scientificName = null;

    #[Assert\Image(maxSize: '10M', mimeTypes: self::PHOTO_MIME_TYPES, mimeTypesMessage: 'planting.photo.mime_types')]
    public ?UploadedFile $photo = null;

    public ?Space $space = null;

    #[Assert\Length(max: 1000)]
    public ?string $diseases = null;

    /**
     * @var array<string, ?\DateTimeImmutable> when each care task was last done, keyed by CareTask values
     */
    #[Assert\All([new Assert\LessThanOrEqual('today', message: 'care_record.in_the_future')])]
    public array $careRecords = [];

    public static function fromPlanting(Planting $planting): self
    {
        $data = new self();
        $data->name = $planting->getName();
        $data->trefleId = $planting->getTrefleId();
        $data->scientificName = $planting->getScientificName();
        $data->space = $planting->getSpace();
        $data->diseases = $planting->getDiseases();
        foreach (CareTask::cases() as $task) {
            $data->careRecords[$task->value] = $planting->lastDone($task);
        }

        return $data;
    }

    /**
     * A new plant of a species picked beforehand: its species stays, its name starts as the common name.
     */
    public static function forPlant(Plant $plant): self
    {
        $data = new self();
        $data->name = $plant->getCommonName();
        $data->trefleId = $plant->getTrefleId();
        $data->scientificName = $plant->getScientificName();

        return $data;
    }

    public function toPlanting(Garden $garden, string $slug, \DateTimeImmutable $createdAt, ?string $photoFilename): Planting
    {
        $scientificName = trim($this->scientificName ?? '');

        $planting = new Planting(
            $garden,
            trim($this->name),
            $slug,
            $this->space,
            self::nullIfBlank($this->diseases),
            $createdAt,
            '' !== $scientificName ? $this->trefleId : null,
            '' !== $scientificName ? $scientificName : null,
            $photoFilename,
        );

        $this->recordCareOf($planting);

        return $planting;
    }

    /**
     * A date changed in the form is a care done that day: the older ones stay in the history.
     */
    public function applyTo(Planting $planting): void
    {
        $planting->revise(trim($this->name), $this->space, self::nullIfBlank($this->diseases));
        $this->recordCareOf($planting);
    }

    private function recordCareOf(Planting $planting): void
    {
        foreach ($this->careRecords as $task => $doneOn) {
            if (null !== $doneOn) {
                $planting->recordCare(CareTask::from($task), $doneOn);
            }
        }
    }

    private static function nullIfBlank(?string $text): ?string
    {
        $text = trim($text ?? '');

        return '' !== $text ? $text : null;
    }
}
