<?php

namespace App\Plant\Form\Model;

use App\DataSource\Trefle\Model\PlantCandidate;
use App\Garden\Entity\Garden;
use App\Garden\Form\Model\SpeciesPickerData;
use App\Garden\Model\SpeciesSearchResult;
use App\Garden\Validator\PickedSpecies;
use App\Plant\Entity\CareSheet;
use App\Plant\Entity\Plant;
use App\Plant\Model\Source;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A species created by hand, without the assistant: picked among the ones Trefle finds for its name, its care sheet
 * filled in afterwards.
 */
#[PickedSpecies]
final class NewPlantFormData implements SpeciesPickerData
{
    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    public string $name = '';

    #[Assert\NotNull(message: 'species.not_picked')]
    #[Assert\Positive]
    public ?int $trefleId = null;

    /**
     * Shown by the picker only: the species comes from Trefle again, never from the browser.
     */
    public ?string $scientificName = null;

    public function pickedAmong(?SpeciesSearchResult $result): PlantCandidate
    {
        $candidate = null !== $this->trefleId ? $result?->find($this->trefleId) : null;

        return $candidate ?? throw new \LogicException('A species is picked in a validated form only.');
    }

    /**
     * A draft with the names of Trefle, and a care sheet to fill in.
     */
    public function toPlant(Garden $garden, PlantCandidate $candidate, \DateTimeImmutable $createdAt): Plant
    {
        return Plant::propose(
            $garden,
            $candidate->commonName ?? trim($this->name),
            $candidate->scientificName,
            // Trefle may not know its family: the card shows none
            $candidate->family ?? '',
            CareSheet::blank([new Source(Source::TREFLE)]),
            $createdAt,
            $candidate->trefleId,
            $candidate->imageUrl,
        );
    }
}
