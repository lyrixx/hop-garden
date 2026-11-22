<?php

namespace App\Twig\Components\PlantCard;

use App\Plant\Entity\Plant;
use App\Plant\Model\CareSheetField;
use App\Plant\Model\CareSheetRow;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * One value of the care sheet. Its th holds the label alone: the tests and the video find the values to verify by it.
 */
#[AsTwigComponent]
final class Row
{
    public Plant $plant;

    public CareSheetRow $row;

    /**
     * @return list<int>|null the months of a field of months, shown on a ruler; null for the other fields
     */
    public function getMonths(): ?array
    {
        $careSheet = $this->plant->getCareSheet();

        return match ($this->row->field) {
            CareSheetField::PruningMonths => $careSheet->getPruningMonths(),
            CareSheetField::DeadheadingMonths => $careSheet->getDeadheadingMonths(),
            CareSheetField::CuttingMonths => $careSheet->getCuttingMonths(),
            default => null,
        };
    }

    /**
     * Only the values of a draft that the assistant is not sure of, or that the user has not filled in yet: the user
     * verifies them before validating.
     */
    public function isToReview(): bool
    {
        return !$this->plant->isValidated() && $this->row->confidence->needsReview();
    }
}
