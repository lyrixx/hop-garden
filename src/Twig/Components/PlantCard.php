<?php

namespace App\Twig\Components;

use App\Plant\CareSheetPresenter;
use App\Plant\Entity\Plant;
use App\Plant\Model\CareSheetRow;
use App\Plate\Model\Plate;
use App\Plate\Model\PlatePart;
use App\Plate\PlateCatalog;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * The care sheet of a species, as a page of a garden journal: its name set large above its plate, one small card per
 * part of the plant with the care it governs and, once validated, its reminder, and the hardiness on a blue band.
 * The same card everywhere (the chat, the page of the species): edited, validated and its reminders switched in its
 * Turbo Frame.
 */
#[AsTwigComponent]
final class PlantCard
{
    public Plant $plant;

    /** @var list<CareSheetRow>|null */
    private ?array $rows = null;

    public function __construct(
        private readonly PlateCatalog $plateCatalog,
        private readonly CareSheetPresenter $careSheetPresenter,
    ) {
    }

    public function getPlate(): Plate
    {
        return $this->plateCatalog->forSpecies($this->plant->getScientificName());
    }

    /**
     * @return list<CareSheetRow>
     */
    public function rowsOf(PlatePart $part): array
    {
        $this->rows ??= $this->careSheetPresenter->rows($this->plant->getCareSheet());

        return array_values(array_filter($this->rows, static fn (CareSheetRow $row): bool => \in_array($row->field, $part->fields(), true)));
    }
}
